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
     * Valide la signature du webhook Campay (sécurité HMAC-SHA256).
     *
     * @param string $payload    Corps brut de la requête webhook
     * @param string $signature  Header X-Campay-Signature
     * @return bool
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        // ✅ Vérifier si la validation HMAC est activée
        $verifySignature = config('payment.campay.webhook_verify_signature', false);
        
        if (!$verifySignature) {
            \Log::info('CampayGateway: Vérification de signature HMAC DÉSACTIVÉE (mode dev)');
            return true;
        }

        // ✅ Lire depuis campay.webhook_secret (CAMPAY_WEBHOOK_SECRET dans .env)
        $secret   = config('campay.webhook_secret', '');
        $expected = hash_hmac('sha256', $payload, $secret);

        // Si pas de secret configuré, on accepte sans vérification HMAC (dev only)
        if (empty($secret)) {
            \Log::warning('CampayGateway: CAMPAY_WEBHOOK_SECRET non configuré — HMAC ignoré (dev mode)');
            return true;
        }

        // ✅ Log de débogage
        \Log::debug('CampayGateway HMAC Validation', [
            'secret_set' => !empty($secret),
            'received_signature' => substr($signature, 0, 20) . '...',
            'expected_signature' => substr($expected, 0, 20) . '...',
            'payload_length' => strlen($payload),
            'match' => hash_equals($expected, $signature),
        ]);

        return hash_equals($expected, $signature);
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
