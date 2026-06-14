<?php

/**
 * 🧪 Test — Retirer un Ancien de Service
 * 
 * Scénario complet:
 * 1. Créer une désignation (ancien devient de service)
 * 2. Vérifier que est_de_service = true
 * 3. Retirer la désignation
 * 4. Vérifier que est_de_service = false
 * 
 * À exécuter dans Laravel Tinker:
 * $ php artisan tinker
 */

// ============================================================================
// ÉTAPE 1: Récupérer les données existantes
// ============================================================================

// Récupérer un pasteur
$pasteur = \App\Models\User::where('role', 'pasteur')->first();
// OU spécifier son ID
$pasteur = \App\Models\User::find(2); // ID 2

// Récupérer un ancien
$ancien = \App\Models\User::where('role', 'ancien')->first();
// OU spécifier son ID  
$ancien = \App\Models\User::find(5); // ID 5

// Vérifier l'église
$church = $ancien->churchId;

echo "Pasteur: {$pasteur->nom} (ID: {$pasteur->id})\n";
echo "Ancien: {$ancien->nom} (ID: {$ancien->id})\n";
echo "Église: {$church}\n\n";

// ============================================================================
// ÉTAPE 2: Créer une désignation (si n'existe pas)
// ============================================================================

$mois = now()->month;      // Mois actuel (6 = juin)
$annee = now()->year;      // Année actuelle (2026)

echo "Créant une désignation pour {$mois}/{$annee}...\n";

$designation = \App\Models\AncienDesignation::create([
    'ancienId'        => $ancien->id,
    'ancienNom'       => $ancien->nom,
    'egliseId'        => $church,
    'mois'            => $mois,
    'annee'           => $annee,
    'designePar'      => $pasteur->id,
    'designeParNom'   => $pasteur->nom,
    'jours_travail'   => json_encode([1, 2, 3, 4, 5]), // Lun-Ven
    'horaire_debut'   => '08:00',
    'horaire_fin'     => '18:00',
    'date_fin_service' => now()->endOfMonth()->setTime(23, 59, 59),
]);

echo "✅ Désignation créée (ID: {$designation->id})\n";
echo "   Date fin service: {$designation->date_fin_service}\n\n";

// ============================================================================
// ÉTAPE 3: Vérifier que l'ancien est de service
// ============================================================================

$ancien->refresh();
echo "État AVANT retrait:\n";
echo "   est_de_service: " . ($ancien->est_de_service ? 'TRUE ✅' : 'FALSE ❌') . "\n\n";

// ============================================================================
// ÉTAPE 4: Retirer la désignation via le Service
// ============================================================================

$service = app(\App\Modules\AncienDesignation\Application\Services\AncienDesignationService::class);

echo "Retirant la désignation (ID: {$designation->id})...\n";
$service->retirer($pasteur, $designation->id);

echo "✅ Désignation retirée\n\n";

// ============================================================================
// ÉTAPE 5: Vérifier que l'ancien n'est plus de service
// ============================================================================

$ancien->refresh();
echo "État APRÈS retrait:\n";
echo "   est_de_service: " . ($ancien->est_de_service ? 'TRUE ✅' : 'FALSE ❌') . "\n";
echo "   Désignation existe? " . (
    \App\Models\AncienDesignation::find($designation->id) ? 'OUI ❌' : 'NON ✅ (supprimée)'
) . "\n\n";

// ============================================================================
// ÉTAPE 6: Test avec plusieurs désignations (ancien a d'autres mois)
// ============================================================================

echo "═══════════════════════════════════════════════════════════════\n";
echo "TEST AVANCÉ: Ancien avec plusieurs désignations\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Créer 2 désignations pour mois différents
$moisActuel = now()->month;
$moisProchain = now()->addMonth()->month;

$des1 = \App\Models\AncienDesignation::create([
    'ancienId'         => $ancien->id,
    'ancienNom'        => $ancien->nom,
    'egliseId'         => $church,
    'mois'             => $moisActuel,
    'annee'            => now()->year,
    'designePar'       => $pasteur->id,
    'designeParNom'    => $pasteur->nom,
    'jours_travail'    => null,
    'horaire_debut'    => null,
    'horaire_fin'      => null,
    'date_fin_service' => now()->endOfMonth()->setTime(23, 59, 59),
]);

$des2 = \App\Models\AncienDesignation::create([
    'ancienId'         => $ancien->id,
    'ancienNom'        => $ancien->nom,
    'egliseId'         => $church,
    'mois'             => $moisProchain,
    'annee'            => now()->year,
    'designePar'       => $pasteur->id,
    'designeParNom'    => $pasteur->nom,
    'jours_travail'    => null,
    'horaire_debut'    => null,
    'horaire_fin'      => null,
    'date_fin_service' => now()->addMonth()->endOfMonth()->setTime(23, 59, 59),
]);

echo "Créé 2 désignations:\n";
echo "  1. Mois {$moisActuel} (ID: {$des1->id})\n";
echo "  2. Mois {$moisProchain} (ID: {$des2->id})\n\n";

$ancien->refresh();
echo "État: est_de_service = " . ($ancien->est_de_service ? 'TRUE ✅' : 'FALSE ❌') . "\n";
echo "Désignations actives: " . \App\Models\AncienDesignation::where('ancienId', $ancien->id)
                                                                ->where('date_fin_service', '>', now())
                                                                ->count() . "\n\n";

// Retirer la 1ère désignation
echo "Retirant la désignation mois {$moisActuel}...\n";
$service->retirer($pasteur, $des1->id);

$ancien->refresh();
echo "État APRÈS retrait de la 1ère:\n";
echo "   est_de_service: " . ($ancien->est_de_service ? 'TRUE ✅ (car il en a une autre)' : 'FALSE ❌') . "\n\n";

// Retirer la 2ème désignation
echo "Retirant la désignation mois {$moisProchain}...\n";
$service->retirer($pasteur, $des2->id);

$ancien->refresh();
echo "État APRÈS retrait de la 2ème:\n";
echo "   est_de_service: " . ($ancien->est_de_service ? 'TRUE ❌ (erreur!)' : 'FALSE ✅ (plus de désignation)') . "\n";
echo "   Désignations actives: " . \App\Models\AncienDesignation::where('ancienId', $ancien->id)
                                                                  ->where('date_fin_service', '>', now())
                                                                  ->count() . "\n\n";

echo "✅ TEST COMPLÉTÉ\n";
