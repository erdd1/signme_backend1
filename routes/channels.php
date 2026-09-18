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
// Tous les membres de cette église peuvent écouter les publications, annonces
// (+ admin, qui voit toutes les églises — voir User::belongsToChurch()).
// ⚠ User::$churchId est en camelCase (pas de colonne/accesseur "church_id") :
// `$user->church_id` renvoie toujours null et faisait donc échouer CETTE
// autorisation pour TOUT utilisateur réel — aucun abonnement au canal de son
// église ne pouvait jamais réussir, cassant le temps réel des publications.
Broadcast::channel('church.{churchId}', function ($user, int $churchId) {
    return $user->belongsToChurch($churchId);
});

// ── Canal personnel de l'utilisateur ──────────────────────────────────────
// Seul l'utilisateur lui-même reçoit ses badges et notifications.
Broadcast::channel('user.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
