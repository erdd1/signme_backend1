<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\EvenementStateTransition;
use App\Models\Publication;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Supprime TOUTES les données créées par `app:seed-demo-data` — repère les
 * mêmes marqueurs (email "test<chiffres>@<domaine quelconque>", titres
 * préfixés "[TEST] ") pour ne jamais toucher aux données réelles. Le domaine
 * n'est volontairement pas fixé : app:seed-demo-data accepte n'importe quel
 * domaine via --domain, seul le préfixe numérique "testNNN@" identifie une
 * donnée de démonstration.
 *
 * Usage : php artisan app:clear-demo-data [--force]
 */
class ClearDemoData extends Command
{
    protected $signature = 'app:clear-demo-data {--force : Ne pas demander de confirmation}';

    protected $description = 'Supprime toutes les données de démonstration générées par app:seed-demo-data';

    private const TITRE_PREFIX = '[TEST] ';

    public function handle(): int
    {
        // Opérateur regex portable : MySQL utilise REGEXP, PostgreSQL utilise ~.
        $regexOperator = DB::connection()->getDriverName() === 'pgsql' ? '~' : 'REGEXP';
        $userIds = User::whereRaw("email $regexOperator '^test[0-9]+@'")->pluck('id');
        $evenementIds = Evenement::where('titre', 'like', self::TITRE_PREFIX . '%')->pluck('id');
        $publicationIds = Publication::withTrashed()->where('titre', 'like', self::TITRE_PREFIX . '%')->pluck('id');

        if ($userIds->isEmpty() && $evenementIds->isEmpty() && $publicationIds->isEmpty()) {
            $this->info('Aucune donnée de démonstration trouvée — rien à supprimer.');
            return self::SUCCESS;
        }

        $this->line("À supprimer : {$userIds->count()} utilisateurs, {$evenementIds->count()} événements, {$publicationIds->count()} publications (+ signatures/contributions liées).");

        if (!$this->option('force') && !$this->confirm('Confirmer la suppression définitive ?', true)) {
            $this->warn('Annulé.');
            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $contributions = Contribution::whereIn('evenementId', $evenementIds)
                ->orWhereIn('userId', $userIds)
                ->delete();

            EvenementStateTransition::whereIn('evenementId', $evenementIds)->delete();
            $evenements = Evenement::whereIn('id', $evenementIds)->delete();

            $signatures = Signature::whereIn('userId', $userIds)->delete();

            DB::table('publication_reads')->whereIn('publicationId', $publicationIds)->delete();
            $publications = Publication::withTrashed()->whereIn('id', $publicationIds)->forceDelete();

            DB::table('groupe_user')->whereIn('userId', $userIds)->delete();
            $users = User::whereIn('id', $userIds)->delete();

            DB::commit();

            $this->info('✅ Données de démonstration supprimées :');
            $this->line("   Utilisateurs    : {$users}");
            $this->line("   Signatures      : {$signatures}");
            $this->line("   Événements      : {$evenements}");
            $this->line("   Contributions   : {$contributions}");
            $this->line("   Publications    : {$publications}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Échec de la suppression : {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
