<?php

namespace Tests\Unit\Auth;

use Tests\TestCase;
use App\Core\Exceptions\ApiException;
use App\Modules\Auth\Application\Services\GoogleTokenVerifier;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;

/**
 * Vérifie que GoogleTokenVerifier rejette tout jeton forgé/rejoué et
 * n'accepte que des réponses tokeninfo légitimes pour CETTE application.
 *
 * On ne peut pas obtenir de vrai ID token signé par Google dans des tests
 * automatisés : on simule donc les réponses de l'endpoint tokeninfo
 * (toujours interrogé en HTTP, jamais décodé localement) pour couvrir
 * chaque cas de rejet.
 */
class GoogleTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '89036303804-acoct8rirtc8ugursmqrerc3p3ni03f5.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    private function verifierWithResponse(array|string $body, int $status = 200): GoogleTokenVerifier
    {
        if ($status >= 400) {
            $mock = new MockHandler([
                new ClientException(
                    'tokeninfo error',
                    new Request('GET', 'tokeninfo'),
                    new Response($status, [], is_string($body) ? $body : json_encode($body))
                ),
            ]);
        } else {
            $mock = new MockHandler([
                new Response($status, [], is_string($body) ? $body : json_encode($body)),
            ]);
        }

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        return new GoogleTokenVerifier($client);
    }

    private function validClaims(array $overrides = []): array
    {
        return array_merge([
            'iss'            => 'https://accounts.google.com',
            'aud'            => self::CLIENT_ID,
            'sub'            => '1234567890',
            'email'          => 'membre@example.com',
            'email_verified' => 'true',
            'name'           => 'Membre Test',
        ], $overrides);
    }

    public function test_accepts_valid_token_for_this_app(): void
    {
        $verifier = $this->verifierWithResponse($this->validClaims());

        $result = $verifier->verify('fake-but-valid-shaped-token');

        $this->assertSame('membre@example.com', $result['email']);
        $this->assertTrue($result['email_verified']);
    }

    public function test_rejects_token_with_wrong_audience(): void
    {
        // Jeton Google valide... mais émis pour une AUTRE application.
        $verifier = $this->verifierWithResponse(
            $this->validClaims(['aud' => 'autre-app-9999.apps.googleusercontent.com'])
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Jeton Google non destiné à cette application.');

        $verifier->verify('token-pour-une-autre-app');
    }

    public function test_rejects_token_with_unexpected_issuer(): void
    {
        $verifier = $this->verifierWithResponse(
            $this->validClaims(['iss' => 'https://evil-issuer.example.com'])
        );

        $this->expectException(ApiException::class);

        $verifier->verify('token-emetteur-suspect');
    }

    public function test_rejects_unverified_email(): void
    {
        $verifier = $this->verifierWithResponse(
            $this->validClaims(['email_verified' => 'false'])
        );

        $this->expectException(ApiException::class);

        $verifier->verify('token-email-non-verifie');
    }

    public function test_rejects_missing_email(): void
    {
        $claims = $this->validClaims();
        unset($claims['email']);
        $verifier = $this->verifierWithResponse($claims);

        $this->expectException(ApiException::class);

        $verifier->verify('token-sans-email');
    }

    public function test_rejects_malformed_or_forged_token(): void
    {
        // Un jeton bricolé/forgé n'est pas un JWT signé par Google : Google
        // répond par une erreur HTTP (jamais un 200 avec des claims).
        $verifier = $this->verifierWithResponse(['error_description' => 'Invalid Value'], 400);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Jeton Google invalide ou expiré.');

        $verifier->verify('ceci-nest-pas-un-vrai-jwt-google');
    }

    public function test_rejects_response_with_error_field_despite_200(): void
    {
        $verifier = $this->verifierWithResponse(['error' => 'invalid_token']);

        $this->expectException(ApiException::class);

        $verifier->verify('token-avec-champ-erreur');
    }

    public function test_throws_when_client_id_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $verifier = $this->verifierWithResponse($this->validClaims());

        $this->expectException(ApiException::class);

        $verifier->verify('peu-importe');
    }
}
