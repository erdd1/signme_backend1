<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Controllers ──────────────────────────────────────────────────────────────
use App\Modules\Auth\Http\AuthController;
use App\Modules\Auth\Http\DeviceController;
use App\Modules\User\Http\UserController;
use App\Modules\Church\Http\ChurchController;
use App\Modules\Signature\Http\SignatureController;
use App\Modules\Signature\Http\SignatureLogsAdminController;
use App\Modules\Publication\Http\PublicationController;
use App\Modules\AncienDesignation\Http\AncienDesignationController;
use App\Modules\Rapport\Http\RapportController;
use App\Modules\Payment\Http\PaymentController;
use App\Modules\Badge\Http\BadgeController;
use App\Modules\Evenement\Http\EvenementController;
use App\Modules\Evenement\Http\ContributionController;
use App\Modules\Evenement\Http\MesContributionsController;
use App\Modules\UserLookup\Http\GroupeController;
use App\Modules\UserLookup\Http\QuartierController;
use App\Modules\UserLookup\Http\VilleController;
use App\Modules\Support\Http\SupportController;
use App\Modules\Push\Http\DeviceTokenController;
use App\Modules\Tresorerie\Http\SortieFinanciereController;
use App\Modules\Retrait\Http\RetraitController;
use App\Modules\AboutChurch\Http\AboutChurchController;
use App\Modules\Celebration\Http\CelebrationController;
use App\Modules\Don\Http\DonController;
use App\Modules\Celebration\Http\Admin\CelebrationTypeController;
use App\Modules\System\Http\SystemController;
use App\Modules\AdminAccess\Http\AdminAccessController;

/*
|=============================================================================
| EEC SIGNCARD — API REST v1
|=============================================================================
|
| Architecture : Domain-Driven Design (DDD) modulaire
| Auth         : Laravel Sanctum (Bearer Token)
| Versioning   : /api/v1/
|
| RBAC (Rôles) :
|   administrateur → Accès total (toutes les églises)
|   pasteur        → Son église (gestion membres, désignation anciens)
|   secretaire     → Son église (membres limités, publications, rapports sec.)
|   tresorier      → Son église (lecture finances)
|   ancien         → Son église (signature présentielle si est_de_service=true)
|   paroissien     → Son compte (signature à distance)
|
*/

// ═════════════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES (No authentication required) — v1
// ═════════════════════════════════════════════════════════════════════════════

Route::prefix('v1')->group(function () {
    // =========================================================================
    // 1. HEALTH CHECK — Public
    // =========================================================================
    Route::get('/health', function () {
        return response()->json([
            'success'     => true,
            'message'     => 'EEC SignCard API is running',
            'version'     => '1.0.0',
            'environment' => config('app.env'),
            'timestamp'   => now()->toDateTimeString(),
        ]);
    });

    // =========================================================================
    // 2. AUTHENTIFICATION — Routes Publiques
    // =========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');
        Route::post('/google', [AuthController::class, 'loginWithGoogle'])
            ->middleware('throttle:auth-login');
        Route::post('/refresh', [AuthController::class, 'refreshToken'])
            ->middleware('throttle:auth-refresh');
        
        // ─── Nouveau flux OTP pour réinitialisation ────────────────────
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:auth-forgot');
        Route::post('/verify-password-reset-otp', [AuthController::class, 'verifyPasswordResetOTP'])
            ->middleware('throttle:auth-verify-otp');
        Route::post('/resend-password-reset-otp', [AuthController::class, 'resendPasswordResetOTP'])
            ->middleware('throttle:auth-resend-otp');
        Route::post('/confirm-password-reset', [AuthController::class, 'confirmPasswordReset'])
            ->middleware('throttle:auth-reset');
        
    });

    // =========================================================================
    // 3. WEBHOOK NOTCHPAY — Public (sécurisé par HMAC-SHA256 sur le corps brut)
    // =========================================================================
    Route::post('/webhooks/notchpay', [PaymentController::class, 'webhook'])
         ->name('payments.webhook');

    // =========================================================================
    // 4. GRILLE DES FRAIS — Public (utile pour affichage Flutter sans auth)
    // =========================================================================
    Route::get('/signatures/fees', [SignatureController::class, 'feesGrid'])
         ->name('signatures.fees');
});


// ═════════════════════════════════════════════════════════════════════════════
// CLIENT API v1 (Mobile App - All roles EXCEPT administrateur)
// ═════════════════════════════════════════════════════════════════════════════
Route::prefix('v1')->middleware(['auth:sanctum', 'check.actif', 'not.admin'])->group(function () {

    // =========================================================================
    // CLIENT AUTH ENDPOINTS (Protected)
    // =========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('/logout',          [AuthController::class, 'logout']);
        Route::get('/me',               [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // ─── PHASE 2 : MFA ─────────────────────────────────────────────────
        Route::post('/mfa/enable',      [AuthController::class, 'enableMFA']);
        Route::post('/mfa/confirm',     [AuthController::class, 'confirmMFA']);
        Route::post('/mfa/disable',     [AuthController::class, 'disableMFA']);
        Route::get('/mfa/status',       [AuthController::class, 'getMFAStatus']);

        // ─── PHASE 2 : Email Verification ──────────────────────────────────
        Route::post('/email/request-verification', [AuthController::class, 'requestEmailVerification']);
        Route::post('/email/verify',    [AuthController::class, 'verifyEmail']);

        // ─── PHASE 2 : Device Management ───────────────────────────────────
        Route::get('/devices',          [DeviceController::class, 'index']);
        Route::delete('/devices/{id}',  [DeviceController::class, 'disconnect']);
        Route::post('/devices/disconnect-others', [DeviceController::class, 'disconnectOthers']);
        Route::post('/devices/{id}/trust', [DeviceController::class, 'markAsTrusted']);

        // ─── PHASE 2 : Security Alerts ────────────────────────────────────
        Route::get('/security/alerts',  [AuthController::class, 'getSecurityAlerts']);
        Route::post('/security/alerts/{id}/review', [AuthController::class, 'reviewSecurityAlert']);

        // ─── PHASE 3 : Notifications ──────────────────────────────────────
        Route::get('/security/notification-preferences', [AuthController::class, 'getNotificationPreferences']);
        Route::post('/security/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);

        // ─── PHASE 3 : Personal Audit Logs ────────────────────────────────
        Route::get('/audit/trail',           [AuthController::class, 'getAuditTrail']);
        Route::get('/audit/logins',          [AuthController::class, 'getRecentLogins']);
        Route::get('/audit/failed-attempts', [AuthController::class, 'getFailedAttempts']);
        Route::get('/audit/password-changes', [AuthController::class, 'getPasswordChanges']);
        Route::get('/audit/token-activity',  [AuthController::class, 'getTokenActivity']);
        Route::get('/audit/trail/export',    [AuthController::class, 'exportAuditTrail']);
    });

    // =========================================================================
    // CLIENT USERS ENDPOINTS (Pasteur, Secrétaire, Ancien de service can see members of their church)
    // =========================================================================
    Route::prefix('users')->group(function () {
        // Get own profile + members of own church (for pasteur/secrétaire/ancien de service)
        Route::get('/',                [UserController::class, 'index'])
             ->middleware('ancien.service');

        // Check if current user can sign in presentiel right now (days/hours)
        // Must be authenticated - checks if user is 'ancien de service' and within their designated hours
        Route::get('/can-sign-presentiel',  [UserController::class, 'canSignPresentielNow'])
             ->middleware('auth:sanctum');

        // Prochain identifiant (matricule) disponible pour un sexe donné —
        // AVANT /{id} pour éviter le conflit de capture.
        Route::get('/next-matricule', [UserController::class, 'nextMatricule'])
             ->middleware('role:pasteur,secretaire');

        // Create new user (pasteur/secrétaire)
        Route::post('/',               [UserController::class, 'store'])
             ->middleware('role:pasteur,secretaire');

        // Tableau de bord "Gérer utilisateurs" (stats + filtres) — pasteur/secrétaire
        // AVANT /{id} pour éviter le conflit de capture.
        Route::get('/stats', [UserController::class, 'stats'])
             ->middleware('role:pasteur,secretaire');

        // Export PDF de la liste des membres — pasteur/secrétaire.
        // AVANT /{id} pour éviter le conflit de capture.
        Route::get('/export-pdf', [UserController::class, 'exportPdf'])
             ->middleware('role:pasteur,secretaire');

        Route::get('/{id}',            [UserController::class, 'show'])
             ->middleware('role:pasteur,secretaire');

        // Modifier un membre (pasteur peut modifier le rôle, secrétaire non)
        // OU sa propre fiche (tous rôles, champs non sensibles uniquement) —
        // pas de middleware de rôle ici : UserService::update() applique les
        // deux règles (gestion d'un AUTRE membre vs auto-édition limitée).
        Route::put('/{id}',            [UserController::class, 'update']);

        // Upload own photo / user photo
        Route::post('/{id}/photo',     [UserController::class, 'uploadPhoto']);

        // Activer/Désactiver un compte (pasteur uniquement — secrétaire révoquée)
        Route::patch('/{id}/toggle-status', [UserController::class, 'toggleStatus'])
             ->middleware('role:pasteur');
    });

    // =========================================================================
    // CLIENT LISTES GÉRÉES (Groupe/Quartier/Ville — champs démographiques users)
    // Lecture : tous les rôles. Écriture : pasteur/secrétaire uniquement.
    // =========================================================================
    Route::prefix('groupes')->group(function () {
        Route::get('/', [GroupeController::class, 'index']);
        Route::middleware('role:pasteur,secretaire')->group(function () {
            Route::post('/', [GroupeController::class, 'store']);
            Route::delete('/{id}', [GroupeController::class, 'destroy']);
        });
    });

    Route::prefix('quartiers')->group(function () {
        Route::get('/', [QuartierController::class, 'index']);
        Route::middleware('role:pasteur,secretaire')->group(function () {
            Route::post('/', [QuartierController::class, 'store']);
            Route::delete('/{id}', [QuartierController::class, 'destroy']);
        });
    });

    Route::prefix('villes')->group(function () {
        Route::get('/', [VilleController::class, 'index']);
        Route::middleware('role:pasteur,secretaire')->group(function () {
            Route::post('/', [VilleController::class, 'store']);
            Route::delete('/{id}', [VilleController::class, 'destroy']);
        });
    });

    // =========================================================================
    // CLIENT SIGNATURES (Read + Create distance)
    // =========================================================================
    Route::prefix('signatures')->group(function () {
        Route::get('/ma-carte',            [SignatureController::class, 'maCarte']);
        Route::post('/distance',           [SignatureController::class, 'initiateDistance']);
        Route::get('/transaction/{transactionId}/status', [SignatureController::class, 'checkTransactionStatus']);
        Route::get('/members/{userId}/status', [SignatureController::class, 'getMemberStatus']);
        Route::post('/presentiel',         [SignatureController::class, 'signPresentiel'])
             ->middleware('ancien.service');
        Route::get('/historique',          [SignatureController::class, 'historique'])
             ->middleware('role:pasteur');
        
        // Historique personnel et traçabilité
        Route::get('/historique-personnel', [SignatureController::class, 'getHistoriquePersonnel']);
        Route::get('/historique-personnel-presentiel', [SignatureController::class, 'getHistoriquePersonnelPresentiel']);
        Route::get('/historique-signatures', [SignatureController::class, 'getHistoriqueSignatures'])
             ->middleware('role:ancien,pasteur');
    });

    // =========================================================================
    // CLIENT PUBLICATIONS (Read + Write for pasteur/secretaire)
    // =========================================================================
    Route::prefix('publications')->group(function () {
        // READ - Tous les rôles
        Route::get('/',       [PublicationController::class, 'index']);
        Route::get('/{id}',   [PublicationController::class, 'show']);

        // WRITE - Pasteur/Secrétaire seulement
        Route::middleware('role:pasteur,secretaire,admin')->group(function () {
            Route::post('/',      [PublicationController::class, 'store']);
            Route::put('/{id}',   [PublicationController::class, 'update']);
            Route::delete('/{id}', [PublicationController::class, 'destroy']);
        });
    });

    // =========================================================================
    // CLIENT ABOUT CHURCH ("À propos de mon Église")
    // Lecture : tous les rôles. Écriture (infos + articles + épinglage) :
    // pasteur/secrétaire uniquement — présentation collective de l'église,
    // pas de restriction "auteur uniquement" contrairement aux Publications.
    // =========================================================================
    Route::prefix('about-church')->group(function () {
        Route::get('/', [AboutChurchController::class, 'show']);

        Route::middleware('role:pasteur,secretaire')->group(function () {
            Route::put('/info',                    [AboutChurchController::class, 'updateInfo']);
            Route::post('/articles',                [AboutChurchController::class, 'storeArticle']);
            Route::put('/articles/{uuid}',          [AboutChurchController::class, 'updateArticle']);
            Route::delete('/articles/{uuid}',       [AboutChurchController::class, 'destroyArticle']);
            Route::post('/articles/{uuid}/pin',     [AboutChurchController::class, 'pinArticle']);
            Route::post('/articles/{uuid}/unpin',   [AboutChurchController::class, 'unpinArticle']);
        });
    });

    // =========================================================================
    // CLIENT EVENEMENTS (Module collecte de fonds — fil d'actualité)
    // =========================================================================
    Route::prefix('evenements')->group(function () {
        // READ - Tous les rôles (filtré par visibilité dans le service)
        Route::get('/',       [EvenementController::class, 'index']);

        // GESTION (liste complète Actif+Archivé+..., avant /{uuid} pour éviter le conflit
        // de capture) - Pasteur/Secrétaire/Trésorier
        Route::get('/gestion', [EvenementController::class, 'gestion'])
             ->middleware('role:pasteur,secretaire,tresorier');

        Route::get('/{uuid}', [EvenementController::class, 'show']);

        // GESTION - Pasteur/Secrétaire (auteur uniquement pour update/publish/etc., cf. service)
        Route::middleware('role:pasteur,secretaire')->group(function () {
            Route::post('/',                       [EvenementController::class, 'store']);
            Route::put('/{uuid}',                  [EvenementController::class, 'update']);
            Route::post('/{uuid}/publish',         [EvenementController::class, 'publish']);
            Route::post('/{uuid}/cancel-scheduled', [EvenementController::class, 'cancelScheduled']);
            Route::post('/{uuid}/terminer',        [EvenementController::class, 'terminer']);
            Route::post('/{uuid}/archiver',        [EvenementController::class, 'archiver']);
            Route::post('/{uuid}/desarchiver',     [EvenementController::class, 'desarchiver']);
        });

        // CONTRIBUTIONS - Tous les rôles peuvent contribuer
        Route::post('/{uuid}/contributions', [ContributionController::class, 'store']);

        // Vue trésorerie par événement - Pasteur/Secrétaire/Trésorier
        Route::get('/{uuid}/contributions', [ContributionController::class, 'index'])
             ->middleware('role:pasteur,secretaire,tresorier');
    });

    Route::get('/contributions/transaction/{uuid}/status', [ContributionController::class, 'status']);

    // Onglet drawer "Contributions événement" - Pasteur/Secrétaire/Trésorier
    // ⚠ Les routes statiques /stats et /filters/events sont déclarées AVANT la
    // route de liste pour éviter tout conflit de capture par le routeur Laravel.
    // Export PDF — pasteur/trésorier uniquement (pas le secrétaire, à la
    // différence des routes de lecture ci-dessous). AVANT la route de liste
    // pour éviter tout conflit de capture.
    Route::get('/contributions-evenements/export-pdf', [ContributionController::class, 'exportPdf'])
         ->middleware('role:pasteur,tresorier');

    Route::middleware('role:pasteur,secretaire,tresorier')->group(function () {
        Route::get('/contributions-evenements/stats',           [ContributionController::class, 'treasuryStats']);
        Route::get('/contributions-evenements/filters/events',  [ContributionController::class, 'filterEvents']);
        Route::get('/contributions-evenements',                 [ContributionController::class, 'allForTreasury']);
    });

    // =========================================================================
    // CLIENT SORTIES FINANCIÈRES (Module trésorerie — Pasteur/Trésorier)
    // =========================================================================
    // ⚠ Route statiques avant les routes paramétrées — éviter conflits.
    Route::middleware('role:pasteur,tresorier')->group(function () {
        Route::prefix('sorties-financieres')->group(function () {
            Route::get('/dashboard', [SortieFinanciereController::class, 'dashboard']);
            Route::post('/simple',   [SortieFinanciereController::class, 'storeSimple']);
            Route::post('/frais',    [SortieFinanciereController::class, 'storeFrais']);
            Route::get('/',          [SortieFinanciereController::class, 'index']);
            Route::put('/{uuid}',    [SortieFinanciereController::class, 'update']);
            Route::delete('/{uuid}', [SortieFinanciereController::class, 'destroy']);
        });
    });

    // =========================================================================
    // CLIENT RETRAITS (Module retrait — Pasteur/Trésorier uniquement)
    // =========================================================================
    // ⚠ Routes statiques (/solde, /beneficiaire) AVANT /{uuid} pour éviter le
    // conflit de capture.
    Route::middleware('role:pasteur,tresorier')->group(function () {
        Route::prefix('retraits')->group(function () {
            Route::get('/solde',                  [RetraitController::class, 'solde']);
            Route::get('/beneficiaire',           [RetraitController::class, 'showBeneficiaire']);
            Route::post('/beneficiaire',          [RetraitController::class, 'storeBeneficiaire']);
            Route::post('/',                      [RetraitController::class, 'store']);
            Route::get('/',                       [RetraitController::class, 'index']);
            Route::post('/{uuid}/recheck',        [RetraitController::class, 'recheck']);
        });
    });

    // =========================================================================
    // CLIENT CELEBRATIONS (Anniversaires + occasions — tous rôles)
    // =========================================================================
    // ⚠ Route statique /read après paramétrée /{uuid} car imbriquée dessous —
    // pas de conflit ici, /{uuid} et /{uuid}/read ne se chevauchent pas.
    Route::prefix('celebrations')->group(function () {
        Route::get('/',            [CelebrationController::class, 'index']);
        Route::get('/{uuid}',      [CelebrationController::class, 'show']);
        Route::post('/{uuid}/read', [CelebrationController::class, 'markRead']);
    });

    // =========================================================================
    // CLIENT DONS (service « Faire un don » hors événement — tous rôles)
    // =========================================================================
    Route::post('/dons', [DonController::class, 'store']);

    // =========================================================================
    // CLIENT MES CONTRIBUTIONS (Module "Mes dépenses" - tous rôles)
    // =========================================================================
    Route::prefix('mes-contributions')->group(function () {
        Route::get('/',      [MesContributionsController::class, 'index']);
        Route::get('/stats', [MesContributionsController::class, 'stats']);
    });

    // =========================================================================
    // CLIENT ANCIEN DESIGNATIONS (Pasteur can manage designations)
    // =========================================================================
    Route::prefix('anciens-designations')
         ->middleware('role:pasteur')
         ->group(function () {
            Route::get('/',  [AncienDesignationController::class, 'index']);
            Route::post('/',  [AncienDesignationController::class, 'store']);
            Route::delete('/{id}',  [AncienDesignationController::class, 'destroy']);
         });

    // =========================================================================
    // CLIENT RAPPORTS (Read only)
    // =========================================================================
    Route::get('/rapports/secretaire', [RapportController::class, 'secretaire'])
         ->middleware('role:pasteur,secretaire');

    Route::get('/rapports/secretaire/pdf', [RapportController::class, 'secretairePdf'])
         ->middleware('role:pasteur,secretaire');

    Route::prefix('rapports/finances')
         ->middleware('role:pasteur,tresorier')
         ->group(function () {
            Route::get('/annuel',    [RapportController::class, 'financesAnnuel']);
            Route::get('/annuel/pdf', [RapportController::class, 'financesAnnuelPdf']);
            Route::get('/mois',      [RapportController::class, 'financesMois']);
         });

    Route::get('/rapports/comparaison', [RapportController::class, 'comparaison'])
         ->middleware('role:pasteur,tresorier');

    // =========================================================================
    // CLIENT PAYMENTS (Read + Initiate)
    // =========================================================================
    Route::prefix('payments')->group(function () {
        Route::get('/',            [PaymentController::class, 'index']);
        Route::get('/{paymentId}', [PaymentController::class, 'show']);
        // NOTE : L'initiation de paiement passe par POST /api/v1/signatures/distance
        // (SignatureService crée signatures + PaymentTransaction de façon atomique)
    });

    // =========================================================================
    // CLIENT NOTIFICATIONS BADGES (Tous rôles)
    // =========================================================================
    Route::prefix('notifications')->group(function () {
        // GET  /api/v1/notifications/badges            → compteurs non-lus
        Route::get('/badges',            [BadgeController::class, 'badges']);
        // POST /api/v1/notifications/badges/mark-read  → reset un compteur
        Route::post('/badges/mark-read', [BadgeController::class, 'markRead']);
    });

    // =========================================================================
    // CLIENT SUPPORT (Contact service client — Tous rôles)
    // =========================================================================
    Route::get('/support/whatsapp', [SupportController::class, 'whatsapp']);

    // =========================================================================
    // CLIENT DEVICE TOKENS (Notifications push FCM — Tous rôles)
    // Uniquement côté client (mobile) : l'admin est un dashboard web, pas
    // d'appareil à enregistrer.
    // =========================================================================
    Route::prefix('device-tokens')->group(function () {
        Route::post('/',   [DeviceTokenController::class, 'store']);
        Route::delete('/', [DeviceTokenController::class, 'destroy']);
    });

}); // fin CLIENT API v1 (role.not:administrateur)

// ═════════════════════════════════════════════════════════════════════════════
// ADMIN LOGIN — Public (pas de token encore) — dashboard web signme-admin
// ═════════════════════════════════════════════════════════════════════════════
Route::prefix('admin')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'loginAdmin'])
         ->middleware('throttle:auth-login');
});

// ═════════════════════════════════════════════════════════════════════════════
// ADMIN API (Web Dashboard - administrateur only)
// ═════════════════════════════════════════════════════════════════════════════
Route::prefix('admin')->middleware(['auth:sanctum', 'check.actif', 'role:administrateur'])->group(function () {

    // =========================================================================
    // ADMIN AUTH ENDPOINTS (Security Management)
    // =========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('/logout',          [AuthController::class, 'logout']);
        Route::get('/me',               [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // ─── PHASE 2 : MFA — doit rester accessible AVANT confirmation MFA,
        // sinon un admin qui n'a pas encore activé/confirmé son MFA ne
        // pourrait jamais atteindre le flux qui le lui permet précisément.
        Route::post('/mfa/enable',      [AuthController::class, 'enableMFA']);
        Route::post('/mfa/confirm',     [AuthController::class, 'confirmMFA']);
        Route::post('/mfa/disable',     [AuthController::class, 'disableMFA']);
        Route::get('/mfa/status',       [AuthController::class, 'getMFAStatus']);

        // ─── PHASE 2 : Email Verification — même raison (bootstrap) ────────
        Route::post('/email/request-verification', [AuthController::class, 'requestEmailVerification']);
        Route::post('/email/verify',    [AuthController::class, 'verifyEmail']);
    });

    // =========================================================================
    // MFA OBLIGATOIRE : tout le reste de l'API admin (hors bootstrap MFA/email
    // ci-dessus) est bloqué tant que l'administrateur n'a pas confirmé son MFA
    // — y compris les endpoints d'audit/sécurité et la gestion des appareils,
    // volontairement déplacés ici (ils ne doivent jamais être lisibles par une
    // session admin qui n'a pas encore confirmé son second facteur).
    // =========================================================================
    Route::middleware('mfa.enabled')->group(function () {

        Route::prefix('auth')->group(function () {
            // ─── PHASE 3 : Admin Security Controls ─────────────────────────
            Route::get('/security-logs',     [AuthController::class, 'getAllSecurityLogs']);
            Route::get('/security-alerts',   [AuthController::class, 'getSystemSecurityAlerts']);
            Route::post('/enforce-mfa/{userId}', [AuthController::class, 'enforceMFAForUser']);

            // ─── Admin Audit Trails ──────────────────────────────────────────
            Route::get('/audit/trail',           [AuthController::class, 'getAuditTrail']);
            Route::get('/audit/all-logins',      [AuthController::class, 'getAllLogins']);
            Route::get('/audit/all-attempts',    [AuthController::class, 'getAllFailedAttempts']);
            Route::get('/audit/trail/export',    [AuthController::class, 'exportAuditTrail']);

            // ─── PHASE 2 : Device Management ─────────────────────────────────
            Route::get('/devices',          [DeviceController::class, 'index']);
            Route::delete('/devices/{id}',  [DeviceController::class, 'disconnect']);
        });

    // =========================================================================
    // ADMIN USER MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('users')->middleware('permission:users')->group(function () {
        Route::get('/',         [UserController::class, 'index']);
        Route::post('/',        [UserController::class, 'store']);
        // AVANT /{id} pour éviter le conflit de capture.
        Route::get('/stats',    [UserController::class, 'stats']);
        Route::get('/next-matricule', [UserController::class, 'nextMatricule']);
        Route::get('/{id}',     [UserController::class, 'show']);
        Route::put('/{id}',     [UserController::class, 'update']);
        Route::delete('/{id}',  [UserController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('/{id}/photo', [UserController::class, 'uploadPhoto']);
    });

    // =========================================================================
    // ADMIN LISTES GÉRÉES (Groupe/Quartier/Ville) — Full CRUD, aucune restriction
    // =========================================================================
    Route::prefix('groupes')->middleware('permission:lookups')->group(function () {
        Route::get('/',        [GroupeController::class, 'index']);
        Route::post('/',       [GroupeController::class, 'store']);
        Route::delete('/{id}', [GroupeController::class, 'destroy']);
    });

    Route::prefix('quartiers')->middleware('permission:lookups')->group(function () {
        Route::get('/',        [QuartierController::class, 'index']);
        Route::post('/',       [QuartierController::class, 'store']);
        Route::delete('/{id}', [QuartierController::class, 'destroy']);
    });

    Route::prefix('villes')->middleware('permission:lookups')->group(function () {
        Route::get('/',        [VilleController::class, 'index']);
        Route::post('/',       [VilleController::class, 'store']);
        Route::delete('/{id}', [VilleController::class, 'destroy']);
    });

    // =========================================================================
    // ADMIN CHURCHES MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('churches')->middleware('permission:churches')->group(function () {
        Route::get('/',             [ChurchController::class, 'index']);
        Route::post('/',            [ChurchController::class, 'store']);
        Route::get('/{id}',         [ChurchController::class, 'show']);
        Route::put('/{id}',         [ChurchController::class, 'update']);
        Route::delete('/{id}',      [ChurchController::class, 'destroy']);
        Route::post('/{id}/pastor', [ChurchController::class, 'assignPastor']);
    });

    // =========================================================================
    // ADMIN ABOUT CHURCH ("À propos de mon Église") — Full access, toutes églises
    // =========================================================================
    Route::prefix('about-church')->middleware('permission:about_church')->group(function () {
        Route::get('/',                       [AboutChurchController::class, 'show']);
        Route::put('/info',                   [AboutChurchController::class, 'updateInfo']);
        Route::post('/articles',              [AboutChurchController::class, 'storeArticle']);
        Route::put('/articles/{uuid}',        [AboutChurchController::class, 'updateArticle']);
        Route::delete('/articles/{uuid}',     [AboutChurchController::class, 'destroyArticle']);
        Route::post('/articles/{uuid}/pin',   [AboutChurchController::class, 'pinArticle']);
        Route::post('/articles/{uuid}/unpin', [AboutChurchController::class, 'unpinArticle']);
    });

    // =========================================================================
    // ADMIN SIGNATURE MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('signatures')->middleware('permission:signatures')->group(function () {
        Route::get('/',               [SignatureController::class, 'index']);
        Route::post('/',              [SignatureController::class, 'store']);
        // ── Routes statiques AVANT /{id} pour éviter le conflit de capture ──
        Route::get('/ma-carte',       [SignatureController::class, 'maCarte']);
        Route::get('/historique',     [SignatureController::class, 'historique']);
        Route::get('/transaction/{transactionId}/status', [SignatureController::class, 'checkTransactionStatus']);
        // ── Routes paramétrées ────────────────────────────────────────────
        Route::get('/{id}',           [SignatureController::class, 'show']);
        Route::put('/{id}',           [SignatureController::class, 'update']);
        Route::delete('/{id}',        [SignatureController::class, 'destroy']);
        Route::post('/{id}/refund',   [SignatureController::class, 'refund']);
        Route::post('/presentiel', [SignatureController::class, 'signPresentiel']);
    });

    // =========================================================================
    // ADMIN SIGNATURE LOGS (Audit/Traçabilité) — READ ONLY
    // =========================================================================
    Route::prefix('signature-logs')->middleware('permission:signatures')->group(function () {
        Route::get('/',                  [SignatureLogsAdminController::class, 'index']);
        Route::get('/stats',             [SignatureLogsAdminController::class, 'stats']);
        Route::get('/{id}',              [SignatureLogsAdminController::class, 'show']);
        Route::get('/user/{userId}',     [SignatureLogsAdminController::class, 'userHistory']);
    });

    // =========================================================================
    // ADMIN ANCIEN DESIGNATION MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('anciens-designations')->middleware('permission:anciens_designations')->group(function () {
        Route::get('/',           [AncienDesignationController::class, 'index']);
        Route::post('/',          [AncienDesignationController::class, 'store']);
        Route::get('/{id}',       [AncienDesignationController::class, 'show']);
        Route::put('/{id}',       [AncienDesignationController::class, 'update']);
        Route::delete('/{id}',    [AncienDesignationController::class, 'destroy']);
        Route::post('/sync',      [AncienDesignationController::class, 'syncFlags']);
    });

    // =========================================================================
    // ADMIN PUBLICATIONS MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('publications')->middleware('permission:publications')->group(function () {
        Route::get('/',       [PublicationController::class, 'index']);
        Route::post('/',      [PublicationController::class, 'store']);
        Route::get('/{id}',   [PublicationController::class, 'show']);
        Route::put('/{id}',   [PublicationController::class, 'update']);
        Route::delete('/{id}', [PublicationController::class, 'destroy']);
    });

    // =========================================================================
    // ADMIN EVENEMENTS MANAGEMENT (Module collecte de fonds)
    // L'admin bypass la contrainte "auteur uniquement" (cf. EvenementService).
    // Aucune route delete : suppression jamais exposée (légal/comptable).
    // =========================================================================
    Route::prefix('evenements')->middleware('permission:evenements')->group(function () {
        Route::get('/',                        [EvenementController::class, 'index']);
        Route::get('/gestion',                 [EvenementController::class, 'gestion']);
        Route::get('/{uuid}',                  [EvenementController::class, 'show']);
        Route::post('/',                       [EvenementController::class, 'store']);
        Route::put('/{uuid}',                  [EvenementController::class, 'update']);
        Route::post('/{uuid}/publish',         [EvenementController::class, 'publish']);
        Route::post('/{uuid}/cancel-scheduled', [EvenementController::class, 'cancelScheduled']);
        Route::post('/{uuid}/terminer',        [EvenementController::class, 'terminer']);
        Route::post('/{uuid}/archiver',        [EvenementController::class, 'archiver']);
        Route::post('/{uuid}/desarchiver',     [EvenementController::class, 'desarchiver']);
        Route::get('/{uuid}/contributions',    [ContributionController::class, 'index']);
    });

    Route::middleware('permission:evenements')->group(function () {
        // ⚠ Routes statiques avant la route de liste (même raison que côté client).
        Route::get('/contributions-evenements/stats',          [ContributionController::class, 'treasuryStats']);
        Route::get('/contributions-evenements/filters/events', [ContributionController::class, 'filterEvents']);
        Route::get('/contributions-evenements',                [ContributionController::class, 'allForTreasury']);

        // ─── Actions avancées contribution (détail complet, revérification, logs) ──
        // Scopé par uuid (jamais par id numérique, cohérent avec tout le module
        // Evenement — pas de collision avec le préfixe client
        // contributions/transaction/{uuid}/status, préfixe complet différent).
        Route::prefix('contributions')->group(function () {
            Route::get('/{uuid}/detail',    [ContributionController::class, 'detail']);
            Route::get('/{uuid}/events',    [ContributionController::class, 'events']);
            Route::get('/{uuid}/sync-logs', [ContributionController::class, 'syncLogs']);
            Route::post('/{uuid}/recheck',  [ContributionController::class, 'recheck']);
            Route::get('/{uuid}/receipt',   [ContributionController::class, 'receipt']);
        });
    });

    // =========================================================================
    // ADMIN SORTIES FINANCIÈRES
    // =========================================================================
    Route::prefix('sorties-financieres')->middleware('permission:sorties_financieres')->group(function () {
        Route::get('/pending',             [SortieFinanciereController::class, 'adminPending']);
        Route::get('/',                    [SortieFinanciereController::class, 'adminIndex']);
        Route::post('/{uuid}/approve',     [SortieFinanciereController::class, 'approve']);
        Route::post('/{uuid}/reject',      [SortieFinanciereController::class, 'reject']);
    });

    // =========================================================================
    // ADMIN CELEBRATIONS
    // =========================================================================
    Route::prefix('celebration-types')->middleware('permission:celebrations')->group(function () {
        Route::get('/',      [CelebrationTypeController::class, 'index']);
        Route::put('/{id}',  [CelebrationTypeController::class, 'update']);
    });
    Route::prefix('celebrations')->middleware('permission:celebrations')->group(function () {
        Route::get('/',          [CelebrationController::class, 'adminIndex']);
        Route::delete('/{uuid}', [CelebrationController::class, 'adminDestroy']);
    });

    // =========================================================================
    // ADMIN PAYMENT/SIGNATURE PAYMENT MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('payments')->middleware('permission:payments')->group(function () {
        Route::get('/',           [PaymentController::class, 'index']);
        Route::post('/',          [PaymentController::class, 'store']);
        Route::get('/{id}',       [PaymentController::class, 'show']);
        Route::put('/{id}',       [PaymentController::class, 'update']);
        Route::delete('/{id}',    [PaymentController::class, 'destroy']);
        Route::post('/{paymentId}/refund', [PaymentController::class, 'refund']);

        // ─── Actions avancées (détail complet, revérification, logs, reçu) ──
        Route::get('/{id}/detail',      [PaymentController::class, 'detail']);
        Route::get('/{id}/events',      [PaymentController::class, 'events']);
        Route::get('/{id}/sync-logs',   [PaymentController::class, 'syncLogs']);
        Route::post('/{id}/recheck',    [PaymentController::class, 'recheck']);
        Route::get('/{id}/receipt',     [PaymentController::class, 'receipt']);
    });

    // =========================================================================
    // ADMIN RAPPORT ENDPOINTS
    // =========================================================================
    Route::prefix('rapports')->middleware('permission:rapports')->group(function () {
        Route::get('/secretaire',  [RapportController::class, 'secretaire']);
        Route::get('/finances/annuel',  [RapportController::class, 'financesAnnuel']);
        Route::get('/finances/mois',    [RapportController::class, 'financesMois']);
        Route::get('/comparaison', [RapportController::class, 'comparaison']);
        Route::get('/export/audit',     [RapportController::class, 'exportAudit']);
        Route::get('/export/security',  [RapportController::class, 'exportSecurity']);
    });

    // =========================================================================
    // ADMIN SUPPORT (Contact service client)
    // =========================================================================
    Route::get('/support/whatsapp', [SupportController::class, 'whatsapp']);

    // =========================================================================
    // ADMIN SYSTEM (Maintenance production : santé, sauvegardes, mode maintenance)
    // Réservé au super admin — jamais accordable via admin_permissions (trop
    // risqué pour être délégué : sauvegardes, restauration destructrice,
    // coupure de service).
    // =========================================================================
    Route::prefix('system')->middleware('super.admin')->group(function () {
        Route::get('/health', [SystemController::class, 'health']);

        Route::get('/backups', [SystemController::class, 'listBackups']);
        Route::post('/backups', [SystemController::class, 'triggerBackup']);
        Route::get('/backups/{uuid}/download', [SystemController::class, 'downloadBackup']);
        Route::post('/backups/{uuid}/restore', [SystemController::class, 'restoreBackup']);

        Route::get('/maintenance', [SystemController::class, 'maintenanceStatus']);
        Route::post('/maintenance/enable', [SystemController::class, 'enableMaintenance']);
        Route::post('/maintenance/disable', [SystemController::class, 'disableMaintenance']);

        Route::get('/logs', [SystemController::class, 'logs']);
        Route::get('/logs/download', [SystemController::class, 'downloadLog']);
    });

    // =========================================================================
    // ADMIN ACCESS (Gestion des mini-admins et de leurs permissions)
    // Réservé au super admin exclusivement.
    // =========================================================================
    Route::prefix('admin-access')->middleware('super.admin')->group(function () {
        Route::get('/resources', [AdminAccessController::class, 'resources']);
        Route::get('/', [AdminAccessController::class, 'index']);
        Route::post('/', [AdminAccessController::class, 'store']);
        Route::get('/{id}', [AdminAccessController::class, 'show']);
        Route::put('/{id}/permissions', [AdminAccessController::class, 'updatePermissions']);
    });

    }); // fin mfa.enabled

}); // fin ADMIN API
