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
        
        // ─── Ancien flux (compatibilité) ───────────────────────────────
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:auth-reset');
    });

    // =========================================================================
    // 3. WEBHOOK CAMPAY — Public (sécurisé par HMAC dans le service)
    // =========================================================================
    Route::post('/webhooks/campay', [PaymentController::class, 'webhook'])
         ->name('payments.webhook');

    // TEST - Route pour vérifier l'état du paiement auprès de Campay
    Route::get('/payments/check/{reference}', function ($reference) {
        try {
            $campayGateway = app(\App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway::class);
            $paymentData = $campayGateway->checkPaymentStatus($reference);
            
            if (!$paymentData) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de vérifier le statut'
                ], 400);
            }
            
            $internalStatus = $campayGateway->mapStatus($paymentData['status'] ?? 'PENDING');
            
            return response()->json([
                'status' => 'success',
                'campay_data' => $paymentData,
                'internal_status' => $internalStatus,
                'message' => "Statut: {$internalStatus}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    });

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
        
        // Create new user (pasteur/secrétaire)
        Route::post('/',               [UserController::class, 'store'])
             ->middleware('role:pasteur,secretaire');
        
        Route::get('/{id}',            [UserController::class, 'show']);
        
        // Update own profile / user profile (pasteur can modify role, secrétaire cannot)
        Route::put('/{id}',            [UserController::class, 'update']);
        
        // Upload own photo / user photo
        Route::post('/{id}/photo',     [UserController::class, 'uploadPhoto']);
        
        // Activate/Deactivate user (pasteur/secrétaire)
        Route::patch('/{id}/toggle-status', [UserController::class, 'toggleStatus'])
             ->middleware('role:pasteur,secretaire');
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

    Route::prefix('rapports/finances')
         ->middleware('role:pasteur,tresorier')
         ->group(function () {
            Route::get('/annuel',    [RapportController::class, 'financesAnnuel']);
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

}); // fin CLIENT API v1 (role.not:administrateur)

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

        // ─── PHASE 3 : Admin Security Controls ─────────────────────────────
        Route::get('/security-logs',     [AuthController::class, 'getAllSecurityLogs']);
        Route::get('/security-alerts',   [AuthController::class, 'getSystemSecurityAlerts']);
        Route::post('/enforce-mfa/{userId}', [AuthController::class, 'enforceMFAForUser']);

        // ─── Admin Audit Trails ────────────────────────────────────────────
        Route::get('/audit/trail',           [AuthController::class, 'getAuditTrail']);
        Route::get('/audit/all-logins',      [AuthController::class, 'getAllLogins']);
        Route::get('/audit/all-attempts',    [AuthController::class, 'getAllFailedAttempts']);
        Route::get('/audit/trail/export',    [AuthController::class, 'exportAuditTrail']);

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
    });

    // =========================================================================
    // ADMIN USER MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('users')->group(function () {
        Route::get('/',         [UserController::class, 'index']);
        Route::post('/',        [UserController::class, 'store']);
        Route::get('/{id}',     [UserController::class, 'show']);
        Route::put('/{id}',     [UserController::class, 'update']);
        Route::delete('/{id}',  [UserController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('/{id}/photo', [UserController::class, 'uploadPhoto']);
    });

    // =========================================================================
    // ADMIN CHURCHES MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('churches')->group(function () {
        Route::get('/',             [ChurchController::class, 'index']);
        Route::post('/',            [ChurchController::class, 'store']);
        Route::get('/{id}',         [ChurchController::class, 'show']);
        Route::put('/{id}',         [ChurchController::class, 'update']);
        Route::delete('/{id}',      [ChurchController::class, 'destroy']);
        Route::post('/{id}/pastor', [ChurchController::class, 'assignPastor']);
    });

    // =========================================================================
    // ADMIN SIGNATURE MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('signatures')->group(function () {
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
    Route::prefix('signature-logs')->group(function () {
        Route::get('/',                  [SignatureLogsAdminController::class, 'index']);
        Route::get('/stats',             [SignatureLogsAdminController::class, 'stats']);
        Route::get('/{id}',              [SignatureLogsAdminController::class, 'show']);
        Route::get('/user/{userId}',     [SignatureLogsAdminController::class, 'userHistory']);
    });

    // =========================================================================
    // ADMIN ANCIEN DESIGNATION MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('anciens-designations')->group(function () {
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
    Route::prefix('publications')->group(function () {
        Route::get('/',       [PublicationController::class, 'index']);
        Route::post('/',      [PublicationController::class, 'store']);
        Route::get('/{id}',   [PublicationController::class, 'show']);
        Route::put('/{id}',   [PublicationController::class, 'update']);
        Route::delete('/{id}', [PublicationController::class, 'destroy']);
    });

    // =========================================================================
    // ADMIN PAYMENT/SIGNATURE PAYMENT MANAGEMENT (Full CRUD)
    // =========================================================================
    Route::prefix('payments')->group(function () {
        Route::get('/',           [PaymentController::class, 'index']);
        Route::post('/',          [PaymentController::class, 'store']);
        Route::get('/{id}',       [PaymentController::class, 'show']);
        Route::put('/{id}',       [PaymentController::class, 'update']);
        Route::delete('/{id}',    [PaymentController::class, 'destroy']);
        Route::post('/{paymentId}/refund', [PaymentController::class, 'refund']);
    });

    // =========================================================================
    // ADMIN RAPPORT ENDPOINTS
    // =========================================================================
    Route::prefix('rapports')->group(function () {
        Route::get('/secretaire',  [RapportController::class, 'secretaire']);
        Route::get('/finances/annuel',  [RapportController::class, 'financesAnnuel']);
        Route::get('/finances/mois',    [RapportController::class, 'financesMois']);
        Route::get('/export/audit',     [RapportController::class, 'exportAudit']);
        Route::get('/export/security',  [RapportController::class, 'exportSecurity']);
    });

}); // fin ADMIN API
