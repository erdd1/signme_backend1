<?php

namespace App\Modules\Payment\Http;

use App\Core\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Evenement\Application\Jobs\SyncEvenementContributionStatusJob;
use App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Infrastructure\ExternalServices\FlutterwaveGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook du paiement par carte (Flutterwave). La page de retour du fidèle est
 * l'existante GET /payment/callback (routes/web.php).
 *
 * Le webhook n'est qu'un DÉCLENCHEUR : après contrôle de sa signature, on
 * planifie la vérification du paiement auprès de l'API Flutterwave, et c'est
 * cette vérification (statut, montant, devise) qui fait foi. Une requête
 * forgée ne peut donc jamais créditer un paiement.
 */
class FlutterwaveWebhookController extends Controller
{
    public function __construct(
        private readonly FlutterwaveGateway $gateway,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->rateLimitService->ensureWithinLimit('webhook', $request->ip());
            $this->rateLimitService->incrementRateLimit('webhook', $request->ip());
        } catch (ApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 429);
        }

        $rawBody = $request->getContent();

        if (!$this->gateway->validateWebhookSignature(
            $rawBody,
            $request->header('verif-hash'),
            $request->header('flutterwave-signature'),
        )) {
            Log::warning('[Flutterwave] webhook : signature invalide');
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $decoded = json_decode($rawBody, true) ?? [];
        $data = $decoded['data'] ?? $decoded;
        $txRef = $data['tx_ref'] ?? null;

        $contribution = $txRef
            ? Contribution::where('payment_reference', $txRef)
                ->where('paymentGateway', Contribution::GATEWAY_FLUTTERWAVE)
                ->first()
            : null;
        $transaction = ($txRef && !$contribution)
            ? PaymentTransaction::where('payment_reference', $txRef)
                ->where('paymentGateway', PaymentTransaction::GATEWAY_FLUTTERWAVE)
                ->first()
            : null;

        // Toujours répondre 200 aux requêtes authentiques : Flutterwave réessaie
        // sinon indéfiniment, y compris pour des références qui ne nous concernent pas.
        if (!$contribution && !$transaction) {
            Log::info('[Flutterwave] webhook sans paiement connu', ['tx_ref' => $txRef]);
            return response()->json(['success' => true]);
        }

        PaymentEventRecorder::recordEvent(
            $transaction?->id,
            $contribution?->id,
            PaymentEvent::TYPE_WEBHOOK,
            'pending',
            ['event' => $decoded['event'] ?? null, 'tx_ref' => $txRef],
        );

        // Contribution ou don : suivi propre aux contributions ; signature :
        // suivi des paiements de signature. Dans les deux cas, c'est la
        // vérification auprès de l'API qui fait foi.
        if ($contribution) {
            SyncEvenementContributionStatusJob::dispatch($contribution->id, $txRef, 0);
        } else {
            SyncPaymentStatusJob::dispatch($transaction->id, $txRef, 0);
        }

        return response()->json(['success' => true]);
    }
}
