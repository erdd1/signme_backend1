<?php

namespace App\Modules\Chant\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\User;
use Firebase\JWT\JWT;

/**
 * Délivre le jeton d'accès au hub Chants : un JWT RS256 de courte durée,
 * signé avec la clé PRIVÉE de l'API principale. Le hub le vérifie avec la
 * clé publique, sans jamais rappeler l'API : une panne de l'un n'entraîne
 * pas celle de l'autre.
 *
 * Contenu minimal : identifiant de l'utilisateur (`sub`) et de son église.
 * Aucune donnée personnelle (nom, e-mail, téléphone) n'est transmise.
 */
class ChantsHubTokenService
{
    /**
     * @return array{token: string, expires_in: int, hub_url: string}
     *
     * @throws ApiException hub désactivé ou clé absente
     */
    public function issue(User $user): array
    {
        $hub = config('chants.hub');

        if (!config('chants.enabled') || empty($hub['url'])) {
            throw ApiException::serviceUnavailable('Le répertoire de chants est momentanément indisponible.');
        }

        $path = $hub['jwt_private_key_path'];
        $privateKey = is_readable($path) ? file_get_contents($path) : false;
        if ($privateKey === false) {
            throw ApiException::serviceUnavailable('Le répertoire de chants est momentanément indisponible.');
        }

        $ttl = max(1, (int) $hub['token_ttl_minutes']) * 60;
        $now = time();

        $token = JWT::encode([
            'iss'    => $hub['issuer'],
            'aud'    => $hub['audience'],
            'sub'    => (string) $user->id,
            'church' => $user->churchId,
            'iat'    => $now,
            'nbf'    => $now,
            'exp'    => $now + $ttl,
        ], $privateKey, 'RS256');

        return ['token' => $token, 'expires_in' => $ttl, 'hub_url' => rtrim($hub['url'], '/')];
    }
}
