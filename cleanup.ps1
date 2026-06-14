# Script de nettoyage automatisé - Windows (PowerShell)
# Supprimer les migrations du nouveau module qui n'a pas fonctionné

Write-Host "============================================"  -ForegroundColor Cyan
Write-Host "🧹 NETTOYAGE - EEC SignCard API"  -ForegroundColor Cyan
Write-Host "============================================"  -ForegroundColor Cyan
Write-Host ""

$projectPath = "C:\Users\NOUETAGNIYVAN\eecsigncard_api"

Write-Host "⚠️  ATTENTION"  -ForegroundColor Yellow
Write-Host "Ce script va:"
Write-Host "1. Supprimer 12 fichiers de migration du 2026-05"
Write-Host "2. Créer une migration de rollback"
Write-Host "3. Afficher les étapes suivantes"
Write-Host ""

$confirm = Read-Host "Continuez? (yes/no)"
if ($confirm -ne "yes") {
    Write-Host "Annulé."
    exit
}

Set-Location $projectPath

Write-Host ""
Write-Host "📝 Phase 1: Suppression des fichiers de migration..." -ForegroundColor Yellow

$filesToDelete = @(
    "database\migrations\2026_05_03_000001_create_refresh_tokens_table.php",
    "database\migrations\2026_05_03_000002_create_login_attempts_table.php",
    "database\migrations\2026_05_03_000003_create_security_logs_table.php",
    "database\migrations\2026_05_03_000004_create_email_verifications_table.php",
    "database\migrations\2026_05_03_000005_add_security_columns_to_users_table.php",
    "database\migrations\2026_05_03_000006_create_mfa_credentials_table.php",
    "database\migrations\2026_05_03_000007_create_device_sessions_table.php",
    "database\migrations\2026_05_03_000008_create_suspicious_activities_table.php",
    "database\migrations\2026_05_03_000009_create_otp_codes_table.php",
    "database\migrations\2026_05_06_000001_add_months_to_payment_transactions.php",
    "database\migrations\2026_05_06_000002_create_signature_logs_table.php",
    "database\migrations\2026_05_06_000003_expand_error_message_in_signature_logs.php"
)

$deletedCount = 0
foreach ($file in $filesToDelete) {
    if (Test-Path $file) {
        Remove-Item $file -Force
        Write-Host "✓ Supprimé: $file" -ForegroundColor Green
        $deletedCount++
    }
    else {
        Write-Host "✗ Pas trouvé: $file" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "✓ $deletedCount fichiers de migration supprimés" -ForegroundColor Green
Write-Host ""
Write-Host "📝 Phase 2: Création de la migration de rollback..." -ForegroundColor Yellow

# Générer la migration
php artisan make:migration rollback_phase1_security_cleanup

Write-Host ""
Write-Host "⚠️  ÉTAPES SUIVANTES:" -ForegroundColor Yellow
Write-Host ""
Write-Host "1. Éditez la migration nouvellement créée:" -ForegroundColor White
Write-Host "   - Ajoutez le code SQL pour supprimer les tables/colonnes"
Write-Host "   - Consultez DIAGNOSTIC_CLEANUP.md pour le code à copier"
Write-Host ""
Write-Host "2. Exécutez la migration:" -ForegroundColor White
Write-Host "   php artisan migrate"
Write-Host ""
Write-Host "3. Vérifiez le statut:" -ForegroundColor White
Write-Host "   php artisan migrate:status"
Write-Host ""
Write-Host "✅ Nettoyage des fichiers complété!" -ForegroundColor Green
