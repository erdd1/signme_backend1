<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions granulaires (lire/créer/modifier/supprimer) accordées par le
 * super admin à un mini-admin, module par module (ex: "publications",
 * "evenements"...). Un mini-admin sans ligne pour un module donné n'y a
 * aucun accès — l'absence de ligne équivaut à "tout refusé", jamais à
 * "tout autorisé" (voir User::hasPermission()).
 *
 * `resource` est une clé applicative fixe (voir App\Core\Services\
 * AdminResourceRegistry), pas un nom de table SQL — un module admin peut
 * couvrir plusieurs tables (ex: "signatures" couvre `signatures` ET
 * `signature_logs`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource', 50);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permissions');
    }
};
