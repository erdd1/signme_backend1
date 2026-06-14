<?php

namespace App\Modules\User\Domain;

/**
 * Class UserEntity
 * 
 * Entité du domaine pour l'utilisateur
 */
class UserEntity
{
    public function __construct(
        public $id,
        public $name,
        public $email,
        public $phone = null,
        public $role = 'user',
        public $status = 'active',
        public $createdAt = null,
        public $updatedAt = null,
    ) {}
}
