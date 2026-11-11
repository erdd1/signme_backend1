<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File d'attente Redis (paiements NotchPay)
    |--------------------------------------------------------------------------
    */
    'queue' => env('PAYMENT_QUEUE', 'payments'),

    'connection' => env('PAYMENT_QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Polling NotchPay (jobs différés après initiation)
    |--------------------------------------------------------------------------
    | Délais en secondes — complète le webhook si l'utilisateur confirme tard.
    */
    'polling' => [
        'delays_seconds' => [5, 10, 15, 25, 40, 60, 90, 120, 180],
        'max_attempts'   => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiration des paiements restés "pending" (ex. worker de queue en panne)
    |--------------------------------------------------------------------------
    | Bien au-delà du dernier délai de polling ci-dessus (180s) pour ne jamais
    | expirer un paiement qui aurait encore une chance légitime d'aboutir.
    | Avant d'expirer quoi que ce soit, une dernière vérification est TOUJOURS
    | faite auprès de NotchPay (voir Expire*StaleJob) — un paiement réellement
    | reçu n'est jamais perdu.
    */
    'pending_expiry_minutes' => (int) env('PAYMENT_PENDING_EXPIRY_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Cache Redis statut transaction (lecture rapide par l'app)
    |--------------------------------------------------------------------------
    */
    'cache_ttl_seconds' => (int) env('PAYMENT_STATUS_CACHE_TTL', 3600),

    'cache_prefix' => 'payment:tx:',

    /*
    |--------------------------------------------------------------------------
    | Paiement par carte bancaire (Flutterwave)
    |--------------------------------------------------------------------------
    | `enabled` = interrupteur général (false : l'option carte disparaît de
    | l'app et le serveur refuse tout paiement par carte).
    | `fee_percent` : frais de carte retenus par le fournisseur, en % du total
    | débité — à renseigner avec le taux réel du contrat marchand. Le fidèle
    | paie ces frais en plus : l'église reçoit exactement le montant prévu.
    */
    'card' => [
        'enabled'     => (bool) env('CARD_PAYMENTS_ENABLED', false),
        'currency'    => env('CARD_PAYMENTS_CURRENCY', 'XAF'),
        'fee_percent' => (float) env('FLUTTERWAVE_CARD_FEE_PERCENT', 4.8),
    ],

    /*
    |--------------------------------------------------------------------------
    | NotchPay
    |--------------------------------------------------------------------------
    */
    'notchpay' => [
        // Désactivable uniquement en local/démo (jamais en production).
        'webhook_verify_signature' => env('NOTCHPAY_WEBHOOK_VERIFY_SIGNATURE', true),

        // Frais retenus sur un retrait (Transfer), en % du montant demandé —
        // voir RetraitFeeCalculatorService : taux non exposé par l'API
        // NotchPay, à renseigner avec la valeur réelle du contrat marchand.
        'transfer_fee_percent' => env('NOTCHPAY_TRANSFER_FEE_PERCENT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retraits (payout NotchPay)
    |--------------------------------------------------------------------------
    */
    'retrait' => [
        'montant_min' => (int) env('RETRAIT_MONTANT_MIN', 1000),
    ],

];
