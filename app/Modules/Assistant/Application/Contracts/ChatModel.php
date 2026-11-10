<?php

namespace App\Modules\Assistant\Application\Contracts;

use App\Core\Exceptions\ApiException;

/**
 * Moteur de conversation (LLM). Permet de changer de fournisseur sans
 * toucher au service ni à l'application mobile.
 */
interface ChatModel
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     *        consignes système incluses, dans l'ordre de la conversation
     * @return string la réponse de l'assistant
     *
     * @throws ApiException si le moteur est indisponible, non configuré ou saturé
     */
    public function complete(array $messages): string;
}
