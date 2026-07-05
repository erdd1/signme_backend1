<?php

namespace App\Core\Services;

use App\Core\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Vérifie que la file d'attente Redis (paiements NotchPay) est joignable
 * AVANT d'initier un paiement. Sans ce garde-fou, un paiement peut être
 * lancé côté NotchPay (l'utilisateur reçoit le PUSH sur son téléphone) alors
 * que le job de suivi post-initiation (planifié sur Redis) échoue aussitôt
 * après — l'utilisateur voit une erreur alors que le paiement a bien été
 * déclenché, sans aucun moyen de le confirmer côté serveur.
 *
 * Partagé par tous les modules qui initient un paiement NotchPay (Signature,
 * Evenement/Contribution) : c'est une vérification d'infrastructure, pas
 * de la logique métier, donc son partage ne mélange pas les deux modules.
 */
class PaymentQueueHealthService
{
    /**
     * @throws ApiException si la file de paiement est indisponible (503)
     */
    public function ensureAvailable(): void
    {
        // 'payment.connection' est le nom de la connexion de QUEUE (queue.php),
        // qui référence elle-même une connexion Redis cliente (par défaut
        // 'default') — on ping cette dernière directement.
        $queueConnection = config('payment.connection', 'redis');
        $redisConnection = config("queue.connections.{$queueConnection}.connection", 'default');

        try {
            Redis::connection($redisConnection)->ping();
        } catch (Throwable $e) {
            Log::error('Paiement bloqué : file Redis indisponible avant initiation NotchPay', [
                'queue_connection' => $queueConnection,
                'redis_connection' => $redisConnection,
                'error'            => $e->getMessage(),
            ]);

            throw ApiException::serviceUnavailable(
                'Service de paiement temporairement indisponible. Veuillez réessayer dans quelques instants.'
            );
        }
    }
}
