<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels — EEC SignCard
|--------------------------------------------------------------------------
|
| Ici on définit les autorisations pour les canaux privés Laravel Reverb.
| Le client Flutter s'authentifie via POST /broadcasting/auth avec son
| token Sanctum, et Laravel vérifie ci-dessous s'il peut accéder au canal.
|
*/

// ── Canal de l'église ──────────────────────────────────────────────────────
// Tous les membres de cette église peuvent écouter les publications, annonces.
Broadcast::channel('church.{churchId}', function ($user, int $churchId) {
    return (int) $user->church_id === $churchId;
});

// ── Canal personnel de l'utilisateur ──────────────────────────────────────
// Seul l'utilisateur lui-même reçoit ses badges et notifications.
Broadcast::channel('user.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
