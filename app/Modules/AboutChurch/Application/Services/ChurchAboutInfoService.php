<?php

namespace App\Modules\AboutChurch\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\ChurchAboutInfo;
use App\Models\User;

/**
 * Service ChurchAboutInfo — Infos permanentes du Card principal
 * ("À propos de mon Église")
 *
 * Lecture : tous les rôles (via AboutChurchService::getAggregatedPayload).
 * Écriture : pasteur, secrétaire, admin uniquement (contrôlé par les
 * middlewares de route, cohérent avec les autres modules gérés).
 */
class ChurchAboutInfoService
{
    public function getForChurch(int $churchId): ?ChurchAboutInfo
    {
        return ChurchAboutInfo::where('churchId', $churchId)->first();
    }

    /**
     * Crée ou met à jour les infos de l'église (upsert — une seule ligne
     * par église, jamais d'historique de versions).
     */
    public function update(User $authUser, array $data): ChurchAboutInfo
    {
        $churchId = $this->resolveChurchId($authUser, $data['churchId'] ?? null);

        $info = ChurchAboutInfo::firstOrNew(['churchId' => $churchId]);
        $info->churchId = $churchId;

        if (array_key_exists('district', $data)) {
            $info->district = $data['district'];
        }
        if (array_key_exists('horairesCulte', $data)) {
            $info->horairesCulte = $data['horairesCulte'];
        }
        if (array_key_exists('activitesMajeures', $data)) {
            $info->activitesMajeures = $data['activitesMajeures'];
        }
        $info->updatedBy = $authUser->id;

        $info->save();

        return $info->fresh();
    }

    private function resolveChurchId(User $authUser, ?int $churchId): int
    {
        if ($authUser->isAdmin()) {
            $id = $churchId ?? $authUser->churchId;
            if (!$id) {
                throw ApiException::badRequest('Veuillez spécifier une église (churchId).');
            }
            return $id;
        }
        return $authUser->churchId;
    }
}
