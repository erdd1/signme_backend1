<?php

namespace App\Modules\Payment\Application\Jobs;

/**
 * @deprecated Utiliser SyncCampayPaymentStatusJob (file Redis payments).
 */
class CheckPaymentStatusJob extends SyncCampayPaymentStatusJob
{
    public function __construct(
        int $paymentId,
        string $campayReference,
    ) {
        parent::__construct(
            transactionId: $paymentId,
            campayReference: $campayReference,
        );
    }
}
