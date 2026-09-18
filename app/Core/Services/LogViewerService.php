<?php

namespace App\Core\Services;

/**
 * Lecture des logs applicatifs pour le tableau de bord admin. Ne charge
 * jamais le fichier entier en mémoire (il peut atteindre plusieurs dizaines
 * de Mo en canal "single") — ne lit que les derniers `$maxBytes` octets,
 * largement suffisants pour quelques centaines d'entrées récentes, y
 * compris leurs traces de pile.
 */
class LogViewerService
{
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function availableLevels(): array
    {
        return self::LEVELS;
    }

    /**
     * Chemin du fichier de log actif, selon le driver du canal par défaut
     * (single: un seul fichier fixe ; daily: un fichier par jour, tourné et
     * purgé automatiquement par Laravel).
     */
    public function currentLogPath(): string
    {
        $channel = config('logging.default');
        $driver = config("logging.channels.{$channel}.driver");

        if ($driver === 'stack') {
            $channel = config("logging.channels.{$channel}.channels.0", 'single');
            $driver = config("logging.channels.{$channel}.driver");
        }

        $basePath = config("logging.channels.{$channel}.path", storage_path('logs/laravel.log'));

        if ($driver === 'daily') {
            $info = pathinfo($basePath);
            return $info['dirname'] . '/' . $info['filename'] . '-' . now()->format('Y-m-d') . '.' . $info['extension'];
        }

        return $basePath;
    }

    /**
     * Dernières entrées du log, les plus récentes en premier.
     *
     * @param int $maxBytes Taille maximale lue depuis la fin du fichier.
     */
    public function tail(int $limit = 200, ?string $level = null, ?string $search = null, int $maxBytes = 3 * 1024 * 1024): array
    {
        $path = $this->currentLogPath();
        if (!is_file($path)) {
            return [];
        }

        $entries = array_reverse($this->parseEntries($this->readTail($path, $maxBytes)));

        if ($level !== null) {
            $level = strtolower($level);
            $entries = array_values(array_filter($entries, fn ($e) => $e['level'] === $level));
        }

        if ($search !== null && $search !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn ($e) => stripos($e['message'], $search) !== false || stripos($e['context'], $search) !== false
            ));
        }

        return array_slice($entries, 0, $limit);
    }

    private function readTail(string $path, int $maxBytes): string
    {
        $size = filesize($path);
        $handle = fopen($path, 'r');
        fseek($handle, max(0, $size - $maxBytes));
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Découpe le contenu en entrées structurées. Chaque entrée commence par
     * une ligne `[YYYY-MM-DD HH:MM:SS] env.LEVEL: message` (format Monolog
     * par défaut de Laravel) ; tout ce qui suit jusqu'à la prochaine entrée
     * (contexte JSON, trace de pile) est rattaché en tant que `context`.
     * Les octets avant la première entrée détectée (ligne coupée par le
     * `fseek` de {@see readTail()}) sont naturellement ignorés.
     */
    private function parseEntries(string $content): array
    {
        $entries = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\S+)\.(\w+): (.*)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $m[1],
                    'env' => $m[2],
                    'level' => strtolower($m[3]),
                    'message' => $m[4],
                    'context' => '',
                ];
            } elseif ($current !== null && $line !== '') {
                $current['context'] .= ($current['context'] === '' ? '' : "\n") . $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        // Traces de pile potentiellement énormes — inutile de tout envoyer
        // au frontend, un aperçu suffit pour orienter le diagnostic.
        foreach ($entries as &$entry) {
            if (mb_strlen($entry['context']) > 4000) {
                $entry['context'] = mb_substr($entry['context'], 0, 4000) . "\n… (tronqué)";
            }
        }

        return $entries;
    }
}
