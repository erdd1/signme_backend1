<?php

namespace App\Modules\Payment\Infrastructure\ExternalServices;

use App\Core\Exceptions\ApiException;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use NotchPay\Beneficiary;
use NotchPay\Exceptions\ApiException as NotchPayApiException;
use NotchPay\NotchPay;
use NotchPay\Payment;
use NotchPay\Transfer;
use Throwable;

/**
 * Class NotchPayGateway
 *
 * Service d'intégration avec l'API NotchPay — remplace CampayGateway.
 * Paiement Mobile Money (Orange Money, MTN Mobile Money) : initialize() +
 * charge() déclenche un PUSH USSD, comme le faisait CampayGateway.
 */
class NotchPayGateway
{
    public function __construct()
    {
        $publicKey = config('services.notchpay.public_key');
        $privateKey = config('services.notchpay.private_key');

        if (!$publicKey || !$privateKey) {
            throw new Exception('Notchpay configuration is missing: NOTCHPAY_PUBLIC_KEY / NOTCHPAY_PRIVATE_KEY not set in .env');
        }

        // L'API NotchPay authentifie chaque requête avec DEUX clés (confirmé
        // via https://developer.notchpay.co/api-reference/authentication) :
        //   - header "Authorization" : clé publique
        //   - header "X-Grant"       : clé privée (requis par les endpoints
        //     protégés par leur GrantedMiddleware, ex. initialize/charge)
        // NotchPay::setApiKey() valide le préfixe de la clé publique côté
        // client, mais sa liste de préfixes acceptés ("b.", "sb.", "pk.",
        // "pk_test.") est obsolète : le dashboard NotchPay actuel peut émettre
        // des clés avec d'autres préfixes non reconnus par cette validation
        // périmée (vérifié y compris sur la branche dev-main du SDK, jamais
        // corrigée en amont). On contourne uniquement cette validation de
        // format si elle rejette la clé, en assignant directement la
        // propriété statique utilisée pour construire le header Authorization
        // (ApiOperations/Request.php) — aucune autre logique du SDK n'est
        // affectée. setPrivateKey() n'a pas ce problème (aucune validation).
        try {
            NotchPay::setApiKey($publicKey);
        } catch (\NotchPay\Exceptions\InvalidArgumentException) {
            NotchPay::$apiKey = $publicKey;
        }
        NotchPay::setPrivateKey($privateKey);
    }

    /**
     * Initie un paiement Mobile Money (Orange Money / MTN Mobile Money).
     *
     * @throws Exception|NotchPayApiException
     */
    public function initiateMobileMoney(
        int $paymentId,
        string $phone,
        string $operator,
        int $amount,
        string $description,
        string $email
    ): string {
        $phone = $this->formatPhoneNumber($phone);
        $channel = $this->mapOperatorToChannel($operator);

        try {
            \Log::info('NotchPay initiateMobileMoney - Envoi requête', [
                'paymentId' => $paymentId,
                'amount'    => $amount,
                'channel'   => $channel,
            ]);

            $payment = Payment::initialize([
                'amount'      => $amount,
                'currency'    => 'XAF',
                'email'       => $email,
                'description' => $description,
                'reference'   => "PAYMENT-{$paymentId}-" . now()->timestamp,
            ]);

            $reference = $this->extractReference($payment);
            if (!$reference) {
                throw new Exception('Aucune référence reçue de NotchPay.');
            }

            // Le corps attendu par l'API NotchPay actuelle (PUT /payments/{reference})
            // imbrique le numéro sous "data" — un champ "account_number" à la
            // racine (ancien format) est silencieusement mal interprété par
            // l'API live et provoque des 500 intermittents côté NotchPay.
            // Cf. vendor/notchpay/notchpay-php/openapi.yaml (processPaymentPut).
            Payment::charge($reference, [
                'channel' => $channel,
                'data' => [
                    'phone'          => $phone,
                    'account_number' => $phone,
                    'country'        => 'CM',
                ],
            ]);

            \Log::info('NotchPay initiateMobileMoney - Paiement initié avec succès', [
                'paymentId' => $paymentId,
                'reference' => $reference,
            ]);

            return $reference;
        } catch (NotchPayApiException $e) {
            \Log::error('NotchPay API Error (mobile money)', [
                'error'     => $e->getMessage(),
                'errors'    => $e->errors,
                'paymentId' => $paymentId,
            ]);
            // Une erreur 4xx de NotchPay (numéro non reconnu par l'opérateur,
            // canal désactivé…) est une erreur de saisie ou de configuration,
            // jamais un « Server Error » opaque pour l'utilisateur.
            throw ApiException::unprocessable(
                "Ce numéro n'est pas reconnu comme un compte {$operator}. Vérifiez l'opérateur choisi et le numéro saisi."
            );
        } catch (GuzzleException $e) {
            // Le SDK NotchPay ne convertit en NotchPayApiException que les
            // erreurs 4xx (voir Request::setHttpResponse) — une panne 5xx
            // côté NotchPay remonte ici sous sa forme Guzzle brute et ne
            // doit JAMAIS atteindre l'utilisateur telle quelle (message
            // technique en anglais, trace Guzzle).
            \Log::error('NotchPay erreur serveur (mobile money)', [
                'error'     => $e->getMessage(),
                'body'      => $this->guzzleResponseBody($e),
                'paymentId' => $paymentId,
            ]);
            throw ApiException::serviceUnavailable(
                'NotchPay est momentanément indisponible. Réessayez dans quelques instants.'
            );
        }
    }

    /**
     * Extrait le corps de réponse brut d'une exception Guzzle, si disponible
     * (utile pour diagnostiquer une 5xx dont le SDK NotchPay ne conserve pas
     * le détail).
     */
    private function guzzleResponseBody(GuzzleException $e): ?string
    {
        if (!method_exists($e, 'getResponse')) return null;
        $response = $e->getResponse();
        return $response?->getBody()->getContents();
    }

    /**
     * Vérifie le statut d'un paiement directement auprès de NotchPay —
     * utile en secours si le webhook n'arrive pas (DEMO/latence réseau).
     *
     * @return array|null Représentation associative de la réponse NotchPay, ou null si indisponible.
     */
    public function verifyPayment(string $reference): ?array
    {
        try {
            \Log::info('NotchPay verifyPayment - Requête', ['reference' => $reference]);

            $payment = Payment::verify($reference);
            $data = json_decode(json_encode($payment), true);

            // Normalise : `status` à la racine est le statut de l'appel API
            // lui-même (toujours "OK" en cas de succès HTTP), PAS le statut
            // du paiement — celui-ci est imbriqué sous `transaction.status`
            // (pending|complete|failed). On écrase donc toujours la racine
            // avec la valeur imbriquée quand elle existe, pour que les
            // appelants (jobs de polling) restent simples, comme avant avec
            // CamPay (réponse déjà plate).
            if (isset($data['transaction']['status'])) {
                $data['status'] = $data['transaction']['status'];
            }

            \Log::info('NotchPay verifyPayment - Réponse', ['reference' => $reference, 'data' => $data]);

            return $data;
        } catch (Throwable $e) {
            \Log::error('NotchPay verifyPayment - Erreur', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Enregistre un bénéficiaire de retrait (Orange Money / MTN Mobile Money)
     * auprès de NotchPay — appelé une seule fois par utilisateur (pasteur/
     * trésorier), réutilisé pour chaque retrait (RetraitBeneficiaire).
     *
     * @return array{id: string, raw: array}
     * @throws Exception|NotchPayApiException
     */
    public function createBeneficiary(string $name, string $phone, string $operator): array
    {
        $formattedPhone = $this->formatPhoneNumber($phone);
        $channel = $this->mapOperatorToChannel($operator);

        try {
            $response = Beneficiary::create([
                'channel'        => $channel,
                'name'           => $name,
                'account_number' => $formattedPhone,
                'phone'          => $formattedPhone,
                'country'        => 'CM',
            ]);

            $data = json_decode(json_encode($response), true);
            $beneficiary = $data['beneficiary'] ?? $data;
            $id = $beneficiary['id'] ?? null;

            if (!$id) {
                throw new Exception('Réponse NotchPay invalide (identifiant bénéficiaire manquant).');
            }

            return ['id' => $id, 'raw' => $beneficiary];
        } catch (NotchPayApiException $e) {
            \Log::error('NotchPay API Error (création bénéficiaire)', [
                'error'  => $e->getMessage(),
                'errors' => $e->errors,
            ]);
            throw $e;
        } catch (GuzzleException $e) {
            \Log::error('NotchPay erreur serveur (création bénéficiaire)', [
                'error' => $e->getMessage(),
                'body'  => $this->guzzleResponseBody($e),
            ]);
            throw ApiException::serviceUnavailable(
                'NotchPay est momentanément indisponible. Réessayez dans quelques instants.'
            );
        }
    }

    /**
     * Déclenche un retrait (Transfer) vers un bénéficiaire déjà enregistré.
     *
     * @return array Représentation associative du transfert NotchPay (id, reference, status, ...).
     * @throws Exception|NotchPayApiException
     */
    public function initiateTransfer(
        string $beneficiaryId,
        int $amount,
        string $description,
        string $reference
    ): array {
        try {
            \Log::info('NotchPay initiateTransfer - Envoi requête', [
                'beneficiaryId' => $beneficiaryId,
                'amount'        => $amount,
                'reference'     => $reference,
            ]);

            $response = Transfer::initialize([
                'amount'      => $amount,
                'currency'    => 'XAF',
                'beneficiary' => $beneficiaryId,
                'description' => $description,
                'reference'   => $reference,
            ]);

            $data = json_decode(json_encode($response), true);
            $transfer = $data['transfer'] ?? $data;

            if (empty($transfer['id'])) {
                throw new Exception('Réponse NotchPay invalide (transfert non initialisé).');
            }

            \Log::info('NotchPay initiateTransfer - Transfert initié', [
                'reference'          => $reference,
                'notchpayTransferId' => $transfer['id'],
                'status'             => $transfer['status'] ?? null,
            ]);

            return $transfer;
        } catch (NotchPayApiException $e) {
            \Log::error('NotchPay API Error (transfert)', [
                'error'     => $e->getMessage(),
                'errors'    => $e->errors,
                'reference' => $reference,
            ]);
            throw $e;
        } catch (GuzzleException $e) {
            \Log::error('NotchPay erreur serveur (transfert)', [
                'error'     => $e->getMessage(),
                'body'      => $this->guzzleResponseBody($e),
                'reference' => $reference,
            ]);
            throw ApiException::serviceUnavailable(
                'NotchPay est momentanément indisponible. Réessayez dans quelques instants.'
            );
        }
    }

    /**
     * Revérifie le statut d'un retrait directement auprès de NotchPay — utile
     * en secours si le webhook `transfer.*` n'arrive pas.
     *
     * @return array|null Représentation associative du transfert, ou null si indisponible.
     */
    public function verifyTransfer(string $reference): ?array
    {
        try {
            \Log::info('NotchPay verifyTransfer - Requête', ['reference' => $reference]);

            $response = Transfer::verify($reference);
            $data = json_decode(json_encode($response), true);
            $transfer = $data['transfer'] ?? $data;

            \Log::info('NotchPay verifyTransfer - Réponse', ['reference' => $reference, 'data' => $transfer]);

            return $transfer;
        } catch (Throwable $e) {
            \Log::error('NotchPay verifyTransfer - Erreur', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Valide la signature HMAC-SHA256 du webhook NotchPay.
     *
     * NotchPay signe le corps BRUT (JSON non ré-encodé) de la requête avec
     * la Hash Key du webhook, transmise dans le header `x-notch-signature`.
     * Contrairement à CamPay (JWT HS256 dans le corps), il faut donc lire
     * le corps brut de la requête ($request->getContent()), pas le tableau
     * PHP décodé ($request->all()).
     */
    public function validateWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $verifySignature = config('payment.notchpay.webhook_verify_signature', true);

        if (!$verifySignature) {
            \Log::warning('NotchPayGateway: Vérification de signature webhook DÉSACTIVÉE (config)');
            return true;
        }

        $hashKey = config('services.notchpay.webhook_hash', '');
        if (empty($hashKey)) {
            \Log::error('NotchPayGateway: NOTCHPAY_WEBHOOK_HASH non configuré — webhook rejeté');
            return false;
        }

        if (!$signatureHeader) {
            \Log::warning('NotchPayGateway: header x-notch-signature manquant');
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $hashKey);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Mappe le statut NotchPay vers le statut interne EEC.
     *
     * @param string $notchpayStatus 'complete' | 'failed' | 'pending' (vocabulaire NotchPay)
     * @return string 'completed' | 'failed' | 'pending'
     */
    public function mapStatus(string $notchpayStatus): string
    {
        return match (strtolower($notchpayStatus)) {
            'complete', 'completed' => 'completed',
            'failed', 'canceled', 'cancelled' => 'failed',
            default => 'pending',
        };
    }

    /**
     * La réponse d'initialisation peut exposer la référence soit au niveau
     * racine, soit sous `transaction.reference` selon l'endpoint — on gère
     * les deux formes pour rester robuste.
     */
    private function extractReference(mixed $payment): ?string
    {
        return $payment->transaction->reference ?? $payment->reference ?? null;
    }

    public function mapOperatorToChannel(string $operator): string
    {
        return match (strtoupper($operator)) {
            'ORANGE MONEY' => 'cm.orange',
            'MTN MOBILE MONEY' => 'cm.mtn',
            default => throw new Exception("Opérateur de paiement inconnu : {$operator}"),
        };
    }

    /**
     * Formate le numéro de téléphone au format NotchPay.
     * Accepte : 6XXXXXXXX, 237XXXXXXXX, +2376XXXXXXXX
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '6')) {
            return '237' . $phone;
        }

        if (str_starts_with($phone, '237')) {
            return $phone;
        }

        throw new Exception('Invalid phone format. Expected format: 6XXXXXXXX or 237XXXXXXXX');
    }
}
