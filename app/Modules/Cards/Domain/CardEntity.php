<?php

namespace App\Modules\Cards\Domain;

/**
 * Class CardEntity
 * 
 * Entité du domaine pour les cartes
 */
class CardEntity
{
    public function __construct(
        public $id,
        public $name,
        public $description = null,
        public $type = 'standard',
        public $status = 'draft',
        public $signatureCount = 0,
        public $createdAt = null,
        public $updatedAt = null,
    ) {}
}
