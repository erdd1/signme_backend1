<?php

namespace App\Modules\Payment\Infrastructure\ExternalServices;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

/**
 * Class CampayGateway
 * 
 * Service d'intégration avec l'API Campay
 * Gère toutes les interactions avec Campay
 */
class CampayGateway
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl;
    private string $webhookUrl;
    private ?string $campayReference = null;

    public function __construct()
    {
        $this->client = new Client();

        // ✅ Source unique : config/campay.php → CAMPAY_* dans .env
        $this->apiKey     = config('campay.api_key');
        $this->baseUrl    = rtrim(config('campay.base_url', 'https://demo.campay.net/api'), '/');
        $this->webhookUrl = config('campay.webhook_url', env('CAMPAY_WEBHOOK_URL'));

        if (!$this->apiKey) {
            throw new Exception('Campay configuration is missing: CAMPAY_API_KEY not set in .env');
        }
        if (!$this->webhookUrl) {
            throw new Exception('Campay configuration is missing: CAMPAY_WEBHOOK_URL not set in .env');
        }
    }

    /**
     * Initier un paiement Campay
     * 
     * @throws GuzzleException|Exception
     */
    public function initiatePayment(
        int $paymentId,
        string $phone,
        int $amount,
        string $description = ''
    ): string {
        try {
            $phone = $this->formatPhoneNumber($phone);

            // ✅ URL collect/ correcte
            $collectUrl = rtrim($this->baseUrl, '/') . '/collect/';

            \Log::info('Campay initiatePayment - Envoi requête', [
                'collectUrl'   => $collectUrl,
                'phone'        => $phone,
                'amount'       => $amount,
                'webhookUrl'   => $this->webhookUrl,
            ]);

            $response = $this->client->post($collectUrl, [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'from'               => $phone,
                    'amount'             => (int) $amount,
                    'currency'           => 'XAF',
                    'description'        => $description,
                    'external_reference' => "PAYMENT-{$paymentId}-" . now()->timestamp,
                    'webhook'            => $this->webhookUrl,  // ✅ Campay notifie cette URL
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            \Log::info('Campay initiatePayment - Réponse reçue', [
                'response' => $data,
            ]);

            // Sauvegarder la référence pour la récupérer plus tard
            $this->campayReference = $data['reference'] ?? null;

            if (!isset($data['reference'])) {
                throw new Exception('No reference received from Campay');
            }

            return $data['reference'];

        } catch (GuzzleException $e) {
            \Log::error('Campay API Error', [
                'error' => $e->getMessage(),
                'paymentId' => $paymentId,
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer la référence Campay
     */
    public function getCampayReference(): ?string
    {
        return $this->campayReference;
    }

    /**
     * Formater le numéro de téléphone au format Campay
     * Accepte : 6XXXXXXXX, 237XXXXXXXX, +2376XXXXXXXX
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Supprimer tous les caractères non numériques
        $phone = preg_replace('/\D/', '', $phone);

        // Si commence par 6 (Cameroun), ajouter 237
        if (str_starts_with($phone, '6')) {
            return '237' . $phone;
        }

        // Si commence par 237
        if (str_starts_with($phone, '237')) {
            return $phone;
        }

        throw new Exception('Invalid phone format. Expected format: 6XXXXXXXX or 237XXXXXXXX');
    }

    /**
     * Valide la signature du webhook Campay.
     *
     * Campay n'envoie PAS de header de signature : la preuve est un JWT HS256
     * transporté dans le champ `signature` du corps JSON, ex. :
     *   { "reference": "...", "status": "...", "signature": "<header>.<payload>.<sig>" }
     * Payload JWT attendu : { "iat", "nbf", "exp", "source": "CamPay" }
     *
     * @param array $payload Corps JSON décodé du webhook (doit contenir 'signature')
     */
    public function validateWebhookSignature(array $payload): bool
    {
        $verifySignature = config('payment.campay.webhook_verify_signature', true);

        if (!$verifySignature) {
            \Log::warning('CampayGateway: Vérification de signature webhook DÉSACTIVÉE (config)');
            return true;
        }

        $secret = config('campay.webhook_secret', '');
        if (empty($secret)) {
            \Log::error('CampayGateway: CAMPAY_WEBHOOK_SECRET non configuré — webhook rejeté');
            return false;
        }

        $jwt = $payload['signature'] ?? null;
        if (!is_string($jwt) || $jwt === '') {
            \Log::warning('CampayGateway: champ "signature" manquant dans le webhook');
            return false;
        }

        return $this->verifyCampayJwtSignature($jwt, $secret);
    }

    /**
     * Vérifie le JWT HS256 envoyé par Campay dans le champ `signature`.
     */
    private function verifyCampayJwtSignature(string $jwt, string $secret): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            \Log::warning('CampayGateway: format JWT webhook invalide');
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true)
        );

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            \Log::warning('CampayGateway: signature JWT webhook invalide');
            return false;
        }

        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (!is_array($claims) || ($claims['source'] ?? null) !== 'CamPay') {
            \Log::warning('CampayGateway: claim "source" du JWT webhook invalide');
            return false;
        }

        $now = now()->timestamp;
        if (isset($claims['exp']) && $now > $claims['exp']) {
            \Log::warning('CampayGateway: JWT webhook expiré');
            return false;
        }
        if (isset($claims['nbf']) && $now < $claims['nbf']) {
            \Log::warning('CampayGateway: JWT webhook pas encore valide (nbf)');
            return false;
        }

        return true;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Mappe le statut Campay vers le statut interne EEC.
     *
     * @param string $campayStatus Statut Campay (SUCCESSFUL, FAILED, PENDING)
     * @return string 'completed' | 'failed' | 'pending'
     */
    public function mapStatus(string $campayStatus): string
    {
        return match (strtoupper($campayStatus)) {
            'SUCCESSFUL' => 'completed',
            'FAILED'     => 'failed',
            default      => 'pending',
        };
    }

    /**
     * Vérifier le statut d'un paiement directement auprès de Campay
     * (Utile en DEMO où les webhooks ne sont pas envoyés automatiquement)
     *
     * @param string $reference La référence Campay du paiement
     * @return array ['reference', 'status', 'amount', 'operator', ...]
     */
    public function checkPaymentStatus(string $reference): ?array
    {
        try {
            $statusUrl = rtrim($this->baseUrl, '/') . '/get_payment/' . $reference . '/';

            \Log::info('Campay checkPaymentStatus - Requête', ['statusUrl' => $statusUrl]);

            $response = $this->client->get($statusUrl, [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            \Log::info('Campay checkPaymentStatus - Réponse', ['data' => $data]);

            return $data;

        } catch (\Exception $e) {
            \Log::error('Campay checkPaymentStatus - Erreur', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }
}
