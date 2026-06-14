#!/bin/bash
# Script de nettoyage automatisé de la base de données EEC SignCard API
# Ce script supprime les migrations et crée une migration rollback

set -e

echo "============================================"
echo "🧹 NETTOYAGE - EEC SignCard API"
echo "============================================"
echo ""

# Couleurs pour le terminal
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROJECT_PATH="/c/Users/NOUETAGNIYVAN/eecsigncard_api"

echo -e "${YELLOW}⚠️  ATTENTION${NC}"
echo "Ce script va:"
echo "1. Supprimer 12 fichiers de migration du 2026-05"
echo "2. Créer une migration de rollback"
echo "3. Supprimer les tables et colonnes de la base de données"
echo ""

read -p "Continuez? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Annulé."
    exit 1
fi

cd "$PROJECT_PATH"

echo ""
echo -e "${YELLOW}📝 Phase 1: Suppression des fichiers de migration...${NC}"

files_to_delete=(
    "database/migrations/2026_05_03_000001_create_refresh_tokens_table.php"
    "database/migrations/2026_05_03_000002_create_login_attempts_table.php"
    "database/migrations/2026_05_03_000003_create_security_logs_table.php"
    "database/migrations/2026_05_03_000004_create_email_verifications_table.php"
    "database/migrations/2026_05_03_000005_add_security_columns_to_users_table.php"
    "database/migrations/2026_05_03_000006_create_mfa_credentials_table.php"
    "database/migrations/2026_05_03_000007_create_device_sessions_table.php"
    "database/migrations/2026_05_03_000008_create_suspicious_activities_table.php"
    "database/migrations/2026_05_03_000009_create_otp_codes_table.php"
    "database/migrations/2026_05_06_000001_add_months_to_payment_transactions.php"
    "database/migrations/2026_05_06_000002_create_signature_logs_table.php"
    "database/migrations/2026_05_06_000003_expand_error_message_in_signature_logs.php"
)

deleted_count=0
for file in "${files_to_delete[@]}"; do
    if [ -f "$file" ]; then
        rm "$file"
        echo -e "${GREEN}✓${NC} Supprimé: $file"
        ((deleted_count++))
    else
        echo -e "${RED}✗${NC} Pas trouvé: $file"
    fi
done

echo ""
echo -e "${YELLOW}📝 Phase 2: Création de la migration de rollback...${NC}"

# Créer la migration de rollback
php artisan make:migration rollback_phase1_security_cleanup

echo ""
echo -e "${YELLOW}⚠️  ACTION REQUISE${NC}"
echo "Éditez la migration nouvellement créée pour inclure le code de suppression"
echo "Consultez le fichier: DIAGNOSTIC_CLEANUP.md"
echo ""
echo "Après modification, exécutez:"
echo "  php artisan migrate"
echo ""
echo -e "${GREEN}✓${NC} $deleted_count fichiers de migration supprimés"
echo ""
