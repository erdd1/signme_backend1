<?php

namespace App\Modules\Payment\Application\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\ApiException;

/**
 * Service Campay — Intégration paiement Mobile Money Cameroun
 *
 * Campay supporte : Orange Money, MTN Mobile Money
 * Flux : Flutter → Laravel → Campay API → PUSH téléphone → Webhook → Confirmation
 *
 * @see https://docs.campay.net
 */
class CampayService
{
    private string $baseUrl;
    private string $appUsername;
    private string $appPassword;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->baseUrl     = config('campay.base_url');
        $this->appUsername = config('campay.app_username');
        $this->appPassword = config('campay.app_password');
    }

    /**
     * Authentification Campay et récupération du token d'accès.
     *
     * @return string Access token Campay
     * @throws ApiException
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::post("{$this->baseUrl}/token/", [
            'username' => $this->appUsername,
            'password' => $this->appPassword,
        ]);

        if (!$response->successful()) {
            Log::error('Campay Auth Error', $response->json() ?? []);
            throw new ApiException('Erreur de connexion au service de paiement.', 503);
        }

        $this->accessToken = $response->json('token');
        return $this->accessToken;
    }

    /**
     * Initie un paiement mobile money Campay.
     *
     * Envoie un PUSH sur le téléphone du paroissien qui doit valider.
     * Retourne une référence de transaction à stocker.
     *
     * @param array $params {
     *   @type string $phone_number   Numéro au format 6XXXXXXXX (9 chiffres, commence par 6)
     *   @type int    $amount         Montant total à débiter (offrande + frais) en FCFA
     *   @type string $description    Description du paiement
     *   @type string $external_ref   Référence interne (ex: signature_{userId}_{month})
     *   @type string $webhook_url    URL du webhook Laravel pour la confirmation
     * }
     * @return array { reference, ussd_code, operator }
     * @throws ApiException
     */
    public function initiatePayment(array $params): array
    {
        $token = $this->getAccessToken();

        // Formater le numéro au format international
        $phone = $this->formatPhone($params['phone_number']);

        Log::info('Campay Payment Init', [
            'phone'  => $phone,
            'amount' => $params['amount'],
            'ref'    => $params['external_ref'],
        ]);

        $response = Http::withToken($token)->post("{$this->baseUrl}/collect/", [
            'amount'              => (string) $params['amount'],
            'currency'            => 'XAF',
            'from'                => $phone,
            'description'         => $params['description'],
            'external_reference'  => $params['external_ref'],
        ]);

        $data = $response->json();

        if (!$response->successful() || empty($data['reference'])) {
            Log::error('Campay Payment Failed', $data ?? []);
            throw new ApiException(
                $data['message'] ?? 'Erreur lors de l\'initiation du paiement. Vérifiez votre numéro et réessayez.',
                400
            );
        }

        Log::info('Campay Payment Initiated', ['reference' => $data['reference']]);

        return [
            'reference'   => $data['reference'],
            'status'      => $data['status'] ?? 'PENDING',
            'ussd_code'   => $data['ussd_code'] ?? null,
            'operator'    => $data['operator'] ?? null,
        ];
    }

    /**
     * Vérifie le statut d'un paiement Campay par référence.
     *
     * Statuts Campay :
     *  - SUCCESSFUL → paiement confirmé
     *  - FAILED      → refusé ou délai dépassé
     *  - PENDING     → en attente de validation (délai 60s)
     *
     * @param string $reference Référence retournée par initiatePayment()
     * @return array { reference, status, amount, currency, operator, phone_number }
     * @throws ApiException
     */
    public function checkPaymentStatus(string $reference): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
                        ->get("{$this->baseUrl}/transaction/{$reference}/");

        if (!$response->successful()) {
            throw new ApiException('Impossible de vérifier le statut du paiement.', 503);
        }

        return $response->json();
    }

    /**
     * Valide la signature du webhook Campay (sécurité).
     *
     * @param string $payload    Corps brut de la requête webhook
     * @param string $signature  Header X-Campay-Signature
     * @return bool
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $secret   = config('campay.webhook_secret');
        $expected = hash_hmac('sha256', $payload, $secret);
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
     * Formate un numéro de téléphone camerounais en format international.
     * 
     * Format acceptés :
     * - 237699803165 (12 chiffres, commence par 237) ✓
     * - 699803165    (9 chiffres, commence par 6) → convertit en 237699803165 ✓
     * 
     * Format rejeté :
     * - 06xxxxxxxx, +237xxxxxxxx, etc.
     * 
     * @throws ApiException si le format n'est pas reconnu
     */
    private function formatPhone(string $phone): string
    {
        // Enlever tous les caractères non-numériques
        $phone = preg_replace('/\D/', '', $phone);

        // Si déjà au format 237XXXXXXXXX (12 chiffres)
        if (strlen($phone) === 12 && str_starts_with($phone, '237')) {
            return $phone;
        }

        // Si au format 6XXXXXXXX (9 chiffres, commence par 6)
        if (strlen($phone) === 9 && str_starts_with($phone, '6')) {
            return '237' . $phone;
        }

        // Format invalide
        throw new ApiException(
            'Format de numéro invalide. Veuillez entrer un numéro au format : 237699803165 ou 699803165',
            400
        );
    }
}
