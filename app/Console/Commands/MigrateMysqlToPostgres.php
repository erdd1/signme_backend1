<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copie toutes les données réelles de la connexion `mysql` vers la
 * connexion `pgsql`, table par table, dans un ordre qui respecte les
 * contraintes de clé étrangère NOT NULL (déterminé dynamiquement depuis le
 * schéma PostgreSQL déjà migré — voir `php artisan migrate --database=pgsql`).
 *
 * Le schéma contient un cycle réel et attendu (ex: churches.pastorId -> users,
 * users.churchId -> churches — une église a un pasteur qui est un
 * utilisateur, un utilisateur appartient à une église) via des colonnes
 * NULLABLES. Ces colonnes-là ne sont PAS utilisées pour déterminer l'ordre
 * d'insertion — elles sont mises à NULL lors de la 1ère passe puis
 * restaurées à leur vraie valeur lors d'une 2e passe, une fois que toutes
 * les tables (et donc toutes les lignes référencées) existent.
 *
 * Tables volontairement exclues (état éphémère/interne à Laravel, jamais
 * des données "métier" de l'église) : cache, cache_locks, sessions, jobs,
 * job_batches, failed_jobs, migrations (déjà correctement peuplée par la
 * migration du schéma PostgreSQL — la copier depuis MySQL corromprait
 * l'historique des migrations PostgreSQL).
 *
 * Usage :
 *   php artisan app:migrate-to-postgres --dry-run   (aperçu sans écrire)
 *   php artisan app:migrate-to-postgres              (copie réelle)
 */
class MigrateMysqlToPostgres extends Command
{
    protected $signature = 'app:migrate-to-postgres {--dry-run : Affiche le plan sans copier aucune donnée}';

    protected $description = 'Copie toutes les données de la connexion mysql vers la connexion pgsql';

    private const EXCLUDED_TABLES = [
        'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
    ];

    private const BATCH_SIZE = 500;

    /** [table => [colonne => table_référencée]] — FK NOT NULL uniquement. */
    private array $hardDependencies = [];

    /** [table => [colonne => table_référencée]] — FK nullable, potentiellement cycliques. */
    private array $softForeignKeys = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::connection('pgsql')->hasTable('users')) {
            $this->error('Le schéma PostgreSQL n\'existe pas encore — lancez d\'abord : php artisan migrate --database=pgsql');
            return self::FAILURE;
        }

        $this->loadForeignKeys();
        $tables = $this->orderedTables();

        $this->info(($dryRun ? '[Aperçu] ' : '') . count($tables) . ' tables à copier, dans cet ordre :');
        $this->line(implode(', ', $tables));
        $this->newLine();

        if (!empty($this->softForeignKeys)) {
            $this->comment('Colonnes cycliques (nullables) différées en 2e passe :');
            foreach ($this->softForeignKeys as $table => $cols) {
                foreach ($cols as $col => $ref) {
                    $this->line("  $table.$col -> $ref");
                }
            }
            $this->newLine();
        }

        if ($dryRun) {
            foreach ($tables as $table) {
                $count = DB::connection('mysql')->table($table)->count();
                $this->line("  $table : $count ligne(s) à copier");
            }
            return self::SUCCESS;
        }

        $this->clearTargetTables($tables);

        $mismatches = [];

        foreach ($tables as $table) {
            $this->copyTable($table);
            $this->resetSequence($table);

            $sourceCount = DB::connection('mysql')->table($table)->count();
            $targetCount = DB::connection('pgsql')->table($table)->count();
            $status = $sourceCount === $targetCount ? '<info>OK</info>' : '<error>ÉCART</error>';
            $this->line("  $table : $sourceCount → $targetCount $status");

            if ($sourceCount !== $targetCount) {
                $mismatches[] = $table;
            }
        }

        $this->newLine();
        $this->info('Restauration des colonnes cycliques différées…');
        $this->backfillSoftForeignKeys();

        $this->newLine();
        if (empty($mismatches)) {
            $this->info('Toutes les tables copiées — les comptages correspondent exactement.');
            return self::SUCCESS;
        }

        $this->error('Écarts de comptage détectés sur : ' . implode(', ', $mismatches));
        return self::FAILURE;
    }

    /**
     * Charge les clés étrangères du schéma PostgreSQL et les répartit en
     * dépendances "dures" (NOT NULL — déterminent l'ordre d'insertion) et
     * "souples" (nullable — jamais utilisées pour l'ordre, potentiellement
     * cycliques, différées en 2e passe).
     */
    private function loadForeignKeys(): void
    {
        $rows = DB::connection('pgsql')->select(
            "SELECT tc.table_name, kcu.column_name, ccu.table_name AS referenced_table, c.is_nullable
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name AND tc.table_schema = ccu.table_schema
             JOIN information_schema.columns c
                ON c.table_schema = kcu.table_schema AND c.table_name = kcu.table_name AND c.column_name = kcu.column_name
             WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'"
        );

        foreach ($rows as $row) {
            if ($row->table_name === $row->referenced_table) {
                continue; // auto-référence (ex: parent_id) — sans effet sur l'ordre entre tables.
            }

            if ($row->is_nullable === 'NO') {
                $this->hardDependencies[$row->table_name][$row->column_name] = $row->referenced_table;
            } else {
                $this->softForeignKeys[$row->table_name][$row->column_name] = $row->referenced_table;
            }
        }
    }

    /**
     * Vide toutes les tables cibles avant la copie, en UNE seule commande
     * TRUNCATE ... CASCADE — laisser PostgreSQL résoudre lui-même l'ordre
     * correct (y compris pour les colonnes cycliques/nullables, qui restent
     * de VRAIES contraintes en base malgré leur exclusion du tri
     * topologique ci-dessus ; les supprimer une table à la fois dans le
     * mauvais ordre violerait ces contraintes). Nécessaire pour deux
     * raisons : certaines tables (ex: celebration_types) sont déjà
     * pré-remplies par une migration de seed (donc en collision de clé
     * primaire avec les données copiées depuis MySQL), et ça rend cette
     * commande sûre à relancer après un échec partiel plutôt que de devoir
     * nettoyer la base à la main.
     */
    private function clearTargetTables(array $tables): void
    {
        $this->info('Nettoyage des tables cibles avant copie…');
        $quoted = implode(', ', array_map(fn ($t) => "\"$t\"", $tables));
        DB::connection('pgsql')->statement("TRUNCATE TABLE $quoted RESTART IDENTITY CASCADE");
    }

    /**
     * Copie une table en batches. Les colonnes listées dans
     * [[softForeignKeys]] pour cette table sont mises à NULL ici (valeurs
     * d'origine conservées côté MySQL, restaurées par [[backfillSoftForeignKeys]]
     * une fois toutes les tables peuplées) — et les booléens MySQL (0/1)
     * sont convertis en vrais booléens PostgreSQL selon le type réel de
     * chaque colonne.
     */
    private function copyTable(string $table): void
    {
        $this->line("Copie de <comment>$table</comment>…");

        $booleanColumns = $this->booleanColumns($table);
        $deferredColumns = array_keys($this->softForeignKeys[$table] ?? []);
        $buffer = [];

        DB::connection('mysql')->table($table)->cursor()->each(function ($row) use (&$buffer, $table, $booleanColumns, $deferredColumns) {
            $data = (array) $row;

            foreach ($booleanColumns as $col) {
                if (array_key_exists($col, $data) && $data[$col] !== null) {
                    $data[$col] = (bool) $data[$col];
                }
            }

            foreach ($deferredColumns as $col) {
                if (array_key_exists($col, $data)) {
                    $data[$col] = null;
                }
            }

            $buffer[] = $data;

            if (count($buffer) >= self::BATCH_SIZE) {
                DB::connection('pgsql')->table($table)->insert($buffer);
                $buffer = [];
            }
        });

        if (!empty($buffer)) {
            DB::connection('pgsql')->table($table)->insert($buffer);
        }
    }

    /**
     * 2e passe : restaure, pour chaque colonne cyclique différée, la vraie
     * valeur lue depuis MySQL (id par id) — exécutée une fois que toutes
     * les tables existent des deux côtés, donc sans risque de violation de
     * clé étrangère.
     */
    private function backfillSoftForeignKeys(): void
    {
        foreach ($this->softForeignKeys as $table => $columns) {
            if (!Schema::connection('mysql')->hasColumn($table, 'id')) {
                $this->warn("  $table : pas de colonne id, restauration ignorée pour " . implode(', ', array_keys($columns)));
                continue;
            }

            foreach (array_keys($columns) as $col) {
                $updated = 0;
                DB::connection('mysql')->table($table)->select('id', $col)->whereNotNull($col)->cursor()->each(function ($row) use ($table, $col, &$updated) {
                    DB::connection('pgsql')->table($table)->where('id', $row->id)->update([$col => $row->{$col}]);
                    $updated++;
                });
                $this->line("  $table.$col : $updated valeur(s) restaurée(s)");
            }
        }
    }

    private function booleanColumns(string $table): array
    {
        $rows = DB::connection('pgsql')->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ? AND data_type = 'boolean'",
            [$table]
        );

        return array_map(fn ($row) => $row->column_name, $rows);
    }

    /**
     * Aligne la séquence auto-incrémentée PostgreSQL sur MAX(id) — sans
     * ça, le prochain INSERT applicatif (après bascule) réutiliserait
     * l'id=1 et entrerait en collision avec les lignes copiées.
     */
    private function resetSequence(string $table): void
    {
        if (!Schema::connection('pgsql')->hasColumn($table, 'id')) {
            return;
        }

        // Certaines tables ont un id UUID (pas d'auto-incrément) — MAX() et
        // les séquences ne s'y appliquent pas, rien à réaligner.
        $type = DB::connection('pgsql')->selectOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'id'",
            [$table]
        );
        if (!$type || !in_array($type->data_type, ['integer', 'bigint', 'smallint'], true)) {
            return;
        }

        DB::connection('pgsql')->statement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM \"$table\"), 1), true)",
            [$table]
        );
    }

    /**
     * Ordre de copie respectant UNIQUEMENT les clés étrangères NOT NULL
     * (tri topologique — les colonnes nullables ne créent jamais de
     * dépendance d'ordre, voir [[loadForeignKeys]]).
     */
    private function orderedTables(): array
    {
        $allTables = array_diff(
            array_map(fn ($row) => $row->table_name, DB::connection('pgsql')->select(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"
            )),
            self::EXCLUDED_TABLES
        );

        $remaining = [];
        foreach ($allTables as $table) {
            $deps = array_values($this->hardDependencies[$table] ?? []);
            $remaining[$table] = array_intersect($deps, $allTables);
        }

        $ordered = [];

        while (!empty($remaining)) {
            $ready = array_keys(array_filter(
                $remaining,
                fn ($deps) => empty(array_intersect($deps, array_keys($remaining)))
            ));

            if (empty($ready)) {
                // Ne devrait jamais arriver avec des dépendances NOT NULL
                // uniquement (un tel cycle rendrait le schéma impossible à
                // peupler) — filet de sécurité pour ne pas boucler indéfiniment.
                $this->warn('Cycle inattendu parmi les dépendances NOT NULL : ' . implode(', ', array_keys($remaining)));
                $ready = array_keys($remaining);
            }

            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }
}
