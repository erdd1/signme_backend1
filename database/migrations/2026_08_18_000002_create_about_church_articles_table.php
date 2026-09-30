<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table about_church_articles — Module "À propos de mon Église"
 *
 * Tout contenu affiché sous le Card principal est un "article" typé, quelle
 * que soit sa nature (pasteur, activité majeure, groupe, histoire, futurs
 * types à venir). `type` est une string libre (validée côté FormRequest via
 * une whitelist applicative, comme App\Models\Publication::TYPE_*) plutôt
 * qu'un enum de colonne DB — ajouter un nouveau type de contenu ne nécessite
 * ainsi jamais de nouvelle migration.
 *
 * `statutPasteur` ne concerne que les articles de type 'pastor' (permet de
 * distinguer pasteur actuel / ancien pasteur dans le filtre "Pasteur actuel").
 *
 * `isPinned` + `pinnedAt` gèrent l'épinglage dans les carrousels de la page
 * (5 pasteurs actuels max, 5 groupes max, 3 activités max — quotas contrôlés
 * côté service, pas en base). `pinnedAt` sert uniquement à trier les items
 * épinglés par ordre d'épinglage (le plus ancien épinglé en premier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('about_church_articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ─── Appartenance ─────────────────────────────────────────────────
            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Type polymorphe ────────────────────────────────────────────────
            // 'pastor' | 'activity' | 'group' | 'history' | ... (whitelist app)
            $table->string('type', 30)->index();

            // ─── Contenu obligatoire ────────────────────────────────────────────
            $table->string('titre', 255);
            $table->longText('contenu');

            // ─── Photos (0 à 3, jamais plus — contrôlé côté FormRequest) ──────
            // Array JSON d'URLs publiques (stockage local), ordre = ordre d'affichage.
            $table->json('images')->nullable();

            // ─── Spécifique aux articles de type 'pastor' ──────────────────────
            $table->enum('statutPasteur', ['actuel', 'ancien'])->nullable();

            // ─── Épinglage (carrousels de la page) ─────────────────────────────
            $table->boolean('isPinned')->default(false);
            $table->timestamp('pinnedAt')->nullable();

            // ─── Auteur (dénormalisé pour perf, cohérent avec Publication) ────
            $table->unsignedBigInteger('auteurId')->nullable();
            $table->foreign('auteurId')->references('id')->on('users')->nullOnDelete();
            $table->string('auteurNom', 255)->nullable();

            $table->timestamps();

            // ─── Index composé : filtrage par type + récupération des épinglés ─
            $table->index(['churchId', 'type', 'isPinned'], 'idx_church_type_pinned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('about_church_articles');
    }
};
