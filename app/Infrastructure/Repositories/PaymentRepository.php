<?php

namespace App\Infrastructure\Repositories;

use App\Domain\Interfaces\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Class PaymentRepository
 * 
 * Implémentation du repository pour les paiements
 */
class PaymentRepository implements PaymentRepositoryInterface
{
    protected $model;

    public function __construct(Model $model = null)
    {
        // À implémenter - utiliser le modèle Payment
    }

    public function create(array $data)
    {
        // À implémenter
    }

    public function findById($id)
    {
        // À implémenter
    }

    public function update($id, array $data)
    {
        // À implémenter
    }

    public function delete($id)
    {
        // À implémenter
    }

    public function getAll($paginate = false)
    {
        // À implémenter
    }
}
