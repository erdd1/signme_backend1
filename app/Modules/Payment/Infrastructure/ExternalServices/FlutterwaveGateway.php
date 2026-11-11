<?php

namespace App\Modules\Payment\Infrastructure\ExternalServices;

use App\Core\Exceptions\ApiException;
use App\Modules\Payment\Domain\PaymentStatusGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paiement par carte bancaire via la page de paiement hébergée Flutterwave
 * (Standard / v3) : le fidèle saisit sa carte sur la page de Flutterwave, les
 * données bancaires ne transitent jamais par SignMe (ni app, ni serveur).
 *
 * Règle d'or : un webhook n'est qu'un déclencheur. Aucun paiement n'est jamais
 * crédité sans avoir été revérifié auprès de l'API (verifyPayment()).
 */
class FlutterwaveGateway implements PaymentStatusGateway
{
    /**
     * Crée la page de paiement et renvoie son adresse.
     *
     * @throws ApiException
     */
    public function createCheckout(
        string $txRef,
        int $amount,
        string $redirectUrl,
        string $email,
        string $name,
        string $description,
    ): string {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/payments', [
            'tx_ref'          => $txRef,
            'amount'          => $amount,
            'currency'        => config('payment.card.currency'),
            'redirect_url'    => $redirectUrl,
            'payment_options' => 'card',
            'customer'        => ['email' => $email, 'name' => $name],
            'customizations'  => ['title' => 'SignMe', 'description' => $description],
        ]));

        $link = $response->json('data.link');
        if ($response->failed() || $response->json('status') !== 'success' || !$link) {
            Log::warning('[Flutterwave] création de la page de paiement refusée', [
                'status' => $response->status(),
                'tx_ref' => $txRef,
            ]);
            throw $this->unavailable();
        }

        return $link;
    }

    /**
     * Statut réel d'un paiement d'après sa référence (tx_ref).
     *
     * @return array{status: string, amount: float|int|null, currency: ?string, reference: string, transaction_id: mixed, gateway: string, card?: array{last4: string, brand: ?string}}|null
     *         null tant que Flutterwave ne connaît pas encore la transaction
     *         (le fidèle n'a pas fini de payer) ou en cas d'erreur
     */
    public function verifyPayment(string $reference): ?array
    {
        try {
            $response = $this->send(fn (PendingRequest $http) => $http->get(
                '/transactions/verify_by_reference',
                ['tx_ref' => $reference]
            ));
        } catch (ApiException) {
            return null;
        }

        $data = $response->json('data');
        if ($response->failed() || $response->json('status') !== 'success' || !is_array($data)) {
            return null;
        }

        $result = [
            'status'         => strtolower((string) ($data['status'] ?? '')),
            'amount'         => $data['amount'] ?? null,
            'currency'       => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'reference'      => (string) ($data['tx_ref'] ?? $reference),
            'transaction_id' => $data['id'] ?? null,
            'gateway'        => 'flutterwave',
        ];

        // Seuls la marque et les 4 derniers chiffres sont conservés (pour
        // « Mes transactions ») : jamais le numéro de carte ni son début.
        if (!empty($data['card']['last_4digits'])) {
            $result['card'] = [
                'last4' => (string) $data['card']['last_4digits'],
                'brand' => isset($data['card']['type']) ? strtoupper((string) $data['card']['type']) : null,
            ];
        }

        return $result;
    }

    /**
     * Vérifie qu'une requête de webhook vient bien de Flutterwave, avec le
     * « secret hash » défini dans le tableau de bord : en-tête `verif-hash`
     * (valeur identique au secret) ou `flutterwave-signature` (HMAC-SHA256 du
     * corps brut, encodé en base64).
     */
    public function validateWebhookSignature(string $rawBody, ?string $verifHash, ?string $signature): bool
    {
        $secret = (string) config('services.flutterwave.secret_hash');
        if ($secret === '') {
            Log::error('[Flutterwave] FLUTTERWAVE_SECRET_HASH non configuré — webhook rejeté');
            return false;
        }

        if ($verifHash !== null && hash_equals($secret, $verifHash)) {
            return true;
        }

        return $signature !== null
            && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature);
    }

    public function mapStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'successful', 'completed' => 'completed',
            'failed', 'cancelled', 'canceled' => 'failed',
            default => 'pending',
        };
    }

    private function http(): PendingRequest
    {
        $key = config('services.flutterwave.secret_key');
        if (!$key) {
            Log::error('[Flutterwave] FLUTTERWAVE_SECRET_KEY manquante');
            throw $this->unavailable();
        }

        return Http::baseUrl(rtrim(config('services.flutterwave.base_url'), '/'))
            ->timeout(config('services.flutterwave.timeout'))
            ->withToken($key)
            ->acceptJson();
    }

    /** @param callable(PendingRequest): \Illuminate\Http\Client\Response $call */
    private function send(callable $call): \Illuminate\Http\Client\Response
    {
        try {
            return $call($this->http());
        } catch (ConnectionException $e) {
            Log::warning('[Flutterwave] injoignable', ['error' => $e->getMessage()]);
            throw $this->unavailable();
        }
    }

    private function unavailable(): ApiException
    {
        return ApiException::serviceUnavailable(
            'Le paiement par carte est momentanément indisponible. Réessayez dans un instant.'
        );
    }
}
