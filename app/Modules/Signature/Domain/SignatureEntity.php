<?php

namespace App\Modules\Signature\Domain;

/**
 * Class SignatureEntity
 * 
 * Entité du domaine pour les signatures
 */
class SignatureEntity
{
    public function __construct(
        public $id,
        public $cardId,
        public $userId,
        public $signatureData,
        public $ipAddress = null,
        public $deviceInfo = null,
        public $location = null,
        public $signedAt = null,
        public $createdAt = null,
    ) {}
}
