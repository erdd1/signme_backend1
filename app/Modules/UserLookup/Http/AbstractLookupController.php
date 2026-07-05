<?php

namespace App\Modules\UserLookup\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\UserLookup\Application\Services\AbstractLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contrôleur de base pour les listes gérées (Groupe, Quartier, Ville) —
 * factorise index()/destroy() (identiques pour les 3 ressources). store()
 * reste dans chaque contrôleur concret pour typer le FormRequest spécifique.
 */
abstract class AbstractLookupController extends Controller
{
    use ApiResponseTrait;

    abstract protected function service(): AbstractLookupService;

    /** Libellé pluriel pour les messages (ex. "Groupes", "Quartiers"). */
    abstract protected function resourceLabelPluriel(): string;

    public function index(Request $request): JsonResponse
    {
        $churchId = $request->get('church_id') ? (int) $request->get('church_id') : null;
        $items = $this->service()->list($request->user(), $churchId);

        return $this->successResponse($items, $this->resourceLabelPluriel() . ' récupéré(e)s avec succès.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service()->delete($request->user(), $id);

        return $this->noContentResponse($this->resourceLabelPluriel() . ' supprimé(e) avec succès.');
    }
}
