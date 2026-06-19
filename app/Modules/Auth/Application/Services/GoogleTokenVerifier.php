<?php

namespace App\Modules\Auth\Application\Services;

use App\Core\Exceptions\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Vérifie les ID tokens Google (Sign In With Google) côté serveur.
 *
 * Utilise l'endpoint public tokeninfo de Google plutôt qu'une librairie
 * cliente : un seul appel HTTP suffit pour valider la signature, l'audience
 * et l'expiration, ce qui évite une dépendance Composer supplémentaire.
 *
 * @see https://developers.google.com/identity/sign-in/web/backend-auth
 */
class GoogleTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function __construct(private readonly Client $client = new Client()) {}

    /**
     * Vérifie l'ID token et retourne les informations du compte Google.
     *
     * @return array{email: string, email_verified: bool, name: ?string, picture: ?string, sub: string}
     * @throws ApiException Si le token est invalide, expiré, ou n'est pas destiné à cette app.
     */
    public function verify(string $idToken): array
    {
        $expectedClientId = config('services.google.client_id');

        if (empty($expectedClientId)) {
            throw new ApiException('Connexion Google non configurée côté serveur.', 500);
        }

        try {
            $response = $this->client->get(self::TOKENINFO_URL, [
                'query' => ['id_token' => $idToken],
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            throw ApiException::unauthorized('Jeton Google invalide ou expiré.');
        }

        $claims = json_decode((string) $response->getBody(), true);

        // ─── Réponse inattendue (tokeninfo répond parfois 200 avec un champ
        //     "error" plutôt qu'un code HTTP d'erreur) ───────────────────────
        if (!is_array($claims) || isset($claims['error'])) {
            throw ApiException::unauthorized('Jeton Google invalide ou expiré.');
        }

        // ─── L'émetteur doit être Google (deux formats possibles selon la
        //     doc officielle) ───────────────────────────────────────────────
        $issuer = $claims['iss'] ?? null;
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw ApiException::unauthorized('Jeton Google invalide : émetteur inattendu.');
        }

        // ─── L'audience doit correspondre au Client ID OAuth de l'app ──────
        $audience = $claims['aud'] ?? null;
        if (!is_string($audience) || !hash_equals($expectedClientId, $audience)) {
            throw ApiException::unauthorized('Jeton Google non destiné à cette application.');
        }

        $email = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? null;
        $isVerified = $emailVerified === true || $emailVerified === 'true';

        if (!is_string($email) || $email === '' || !$isVerified) {
            throw ApiException::unauthorized('Email Google non vérifié.');
        }

        return [
            'email'          => strtolower($email),
            'email_verified' => true,
            'name'           => $claims['name'] ?? null,
            'picture'        => $claims['picture'] ?? null,
            'sub'            => $claims['sub'] ?? '',
        ];
    }
}
