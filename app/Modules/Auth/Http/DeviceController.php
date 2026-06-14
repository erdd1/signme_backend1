<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Auth\Application\Services\DeviceManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DeviceController — Gestion des appareils connectés
 *
 * Permet à l'utilisateur de:
 * - Voir la liste de ses appareils connectés
 * - Déconnecter un appareil spécifique
 * - Déconnecter tous les autres appareils
 */
class DeviceController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly DeviceManagementService $deviceService,
    ) {}

    /**
     * GET /api/v1/devices
     *
     * Récupère la liste de tous les appareils connectés de l'utilisateur
     * avec leur nom, type, IP, OS, dernière activité, etc.
     *
     * Réponse: { success, message, data: [ { id, name, device_type, ip_address, os, browser, is_trusted, last_activity } ] }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $devices = $this->deviceService->getActiveDevices($user);

        return $this->successResponse(
            $devices,
            'Appareils connectés récupérés.'
        );
    }

    /**
     * DELETE /api/v1/devices/{id}
     *
     * Déconnecter un appareil spécifique
     * Révoque ses refresh tokens + supprime la session
     *
     * Réponse: { success, message }
     */
    public function disconnect(Request $request, int $deviceId): JsonResponse
    {
        $user = $request->user();

        // Ne pas permettre de déconnecter l'appareil actuel (on vérifierait le device_id)
        // Pour maintenant, simple suppression
        $removed = $this->deviceService->removeDevice($user, $deviceId);

        if (!$removed) {
            return $this->errorResponse(
                'Appareil non trouvé ou déjà déconnecté.',
                404
            );
        }

        return $this->successResponse(
            null,
            'Appareil déconnecté avec succès.'
        );
    }

    /**
     * POST /api/v1/devices/disconnect-others
     *
     * Déconnecter tous les autres appareils (garder seulement celui-ci)
     *
     * Body: { device_id? }
     * Réponse: { success, message }
     */
    public function disconnectOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentDeviceId = $request->input('device_id'); // device_id de l'appareil actuel (optionnel)

        $this->deviceService->removeAllOtherDevices($user, $currentDeviceId);

        return $this->successResponse(
            null,
            'Tous les autres appareils ont été déconnectés.'
        );
    }

    /**
     * POST /api/v1/devices/{id}/trust
     *
     * Marquer un appareil comme approuvé (de confiance)
     * Évite les demandes MFA récurrentes
     *
     * Réponse: { success, message }
     */
    public function markAsTrusted(Request $request, int $deviceId): JsonResponse
    {
        $user = $request->user();
        $device = $user->devices()->find($deviceId);

        if (!$device) {
            return $this->errorResponse('Appareil non trouvé.', 404);
        }

        $device->update(['is_trusted' => true]);

        return $this->successResponse(
            null,
            'Appareil marqué comme approuvé.'
        );
    }
}
