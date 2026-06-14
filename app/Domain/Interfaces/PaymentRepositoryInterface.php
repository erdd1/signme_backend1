<?php

namespace App\Domain\Interfaces;

/**
 * Interface PaymentRepositoryInterface
 * 
 * Contrat pour le repository de paiement
 */
interface PaymentRepositoryInterface
{
    /**
     * Créer un paiement
     */
    public function create(array $data);

    /**
     * Trouver par ID
     */
    public function findById($id);

    /**
     * Mettre à jour
     */
    public function update($id, array $data);

    /**
     * Supprimer
     */
    public function delete($id);

    /**
     * Récupérer tous
     */
    public function getAll($paginate = false);
}
