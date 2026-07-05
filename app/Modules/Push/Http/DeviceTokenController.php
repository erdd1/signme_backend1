<?php

namespace App\Modules\Push\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Push\Application\Services\DeviceTokenService;
use App\Modules\Push\Http\Requests\RegisterDeviceTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DeviceTokenController — Jetons FCM (notifications push)
 *
 * POST   /api/v1/device-tokens → Enregistrer/mettre à jour le jeton de cet appareil [tous rôles]
 * DELETE /api/v1/device-tokens → Retirer le jeton de cet appareil (déconnexion) [tous rôles]
 */
class DeviceTokenController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly DeviceTokenService $deviceTokenService
    ) {}

    public function store(RegisterDeviceTokenRequest $request): JsonResponse
    {
        $this->deviceTokenService->register(
            $request->user(),
            $request->validated('token'),
            $request->validated('platform'),
            $request->validated('timezone'),
        );

        return $this->successResponse(null, 'Jeton enregistré avec succès.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:512']]);

        $this->deviceTokenService->unregister($request->user(), $request->string('token'));

        return $this->noContentResponse('Jeton retiré avec succès.');
    }
}
