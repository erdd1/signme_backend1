#!/bin/bash

################################################################################
# 🧪 TEST API — Retirer un Ancien de Service
#
# Pour tester l'endpoint DELETE /api/v1/anciens-designations/{id}
# 
# Prérequis:
# - Laravel en local (http://localhost:8000)
# - Bearer token d'un pasteur (ou admin)
# - Base de données avec données de test
#
# Usage:
# chmod +x test_retirer_ancien.sh
# ./test_retirer_ancien.sh
################################################################################

# Configuration
API_BASE_URL="http://localhost:8000/api/v1"
# Remplacer par votre bearer token (obtenir via POST /auth/login)
BEARER_TOKEN="YOUR_PASTEUR_TOKEN_HERE"

# Couleurs pour output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}🧪 TEST: Retirer un Ancien de Service${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

# ============================================================================
# ÉTAPE 1: Lister les anciens du mois actuel
# ============================================================================

echo -e "${YELLOW}ÉTAPE 1: Récupérer les anciens du mois${NC}"
echo "GET $API_BASE_URL/anciens-designations?mois=6&annee=2026"
echo ""

RESPONSE=$(curl -s -X GET "$API_BASE_URL/anciens-designations?mois=6&annee=2026" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json")

echo "$RESPONSE" | jq '.'
echo ""

# Extraire le premier ancien désigné
ANCIEN_ID=$(echo "$RESPONSE" | jq -r '.data.designes[0].id // empty' 2>/dev/null)

if [ -z "$ANCIEN_ID" ]; then
  echo -e "${RED}❌ Aucun ancien désigné trouvé. Créons-en un d'abord...${NC}\n"
  
  # ============================================================================
  # CRÉER UNE DÉSIGNATION D'ABORD
  # ============================================================================
  
  echo -e "${YELLOW}Créant une désignation...${NC}"
  
  CREATE_RESPONSE=$(curl -s -X POST "$API_BASE_URL/anciens-designations" \
    -H "Authorization: Bearer $BEARER_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{
      "ancien_id": 5,
      "mois": 6,
      "annee": 2026,
      "jours_travail": [1, 2, 3, 4, 5],
      "horaire_debut": "08:00",
      "horaire_fin": "18:00"
    }')
  
  echo "$CREATE_RESPONSE" | jq '.'
  DESIGNATION_ID=$(echo "$CREATE_RESPONSE" | jq -r '.data.id')
  
  echo -e "${GREEN}✅ Désignation créée (ID: $DESIGNATION_ID)${NC}\n"
else
  echo -e "${GREEN}✅ Ancien trouvé (ID: $ANCIEN_ID)${NC}\n"
  
  # Récupérer l'ID de la désignation depuis la liste
  DESIGNATION_ID=$(echo "$RESPONSE" | jq -r ".data.designes[] | select(.id == $ANCIEN_ID) | .id" 2>/dev/null | head -1)
  
  # Si on ne l'a pas, récupérer directement depuis la DB
  if [ -z "$DESIGNATION_ID" ]; then
    DESIGNATION_ID=$(echo "$RESPONSE" | jq -r '.data.designes[0]._id // .data.designes[0].designation_id // empty' 2>/dev/null)
  fi
fi

if [ -z "$DESIGNATION_ID" ]; then
  echo -e "${RED}❌ Impossible de récupérer l'ID de la désignation${NC}"
  echo "Response: $RESPONSE"
  exit 1
fi

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

# ============================================================================
# ÉTAPE 2: Vérifier l'état AVANT le retrait
# ============================================================================

echo -e "${YELLOW}ÉTAPE 2: Vérifier l'état AVANT${NC}"
echo "GET $API_BASE_URL/anciens-designations?mois=6&annee=2026"
echo ""

BEFORE=$(curl -s -X GET "$API_BASE_URL/anciens-designations?mois=6&annee=2026" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json")

echo "$BEFORE" | jq '.data | {total_designes, designes: [.designes[] | {id, nom, est_designe, disponibilite}]}'
echo ""

TOTAL_BEFORE=$(echo "$BEFORE" | jq '.data.total_designes')
echo -e "${GREEN}✅ Total anciens désignés avant: $TOTAL_BEFORE${NC}\n"

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

# ============================================================================
# ÉTAPE 3: Retirer l'ancien (DELETE)
# ============================================================================

echo -e "${YELLOW}ÉTAPE 3: Retirer l'ancien de service${NC}"
echo "DELETE $API_BASE_URL/anciens-designations/$DESIGNATION_ID"
echo ""

DELETE_RESPONSE=$(curl -s -X DELETE "$API_BASE_URL/anciens-designations/$DESIGNATION_ID" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$DELETE_RESPONSE" | tail -n 1)
BODY=$(echo "$DELETE_RESPONSE" | head -n -1)

echo "HTTP Code: $HTTP_CODE"
echo "Body: $BODY"
echo ""

if [ "$HTTP_CODE" == "204" ]; then
  echo -e "${GREEN}✅ Retrait réussi (HTTP 204 No Content)${NC}\n"
else
  echo -e "${RED}❌ Erreur lors du retrait (HTTP $HTTP_CODE)${NC}\n"
  exit 1
fi

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

# ============================================================================
# ÉTAPE 4: Vérifier l'état APRÈS le retrait
# ============================================================================

echo -e "${YELLOW}ÉTAPE 4: Vérifier l'état APRÈS${NC}"
echo "GET $API_BASE_URL/anciens-designations?mois=6&annee=2026"
echo ""

AFTER=$(curl -s -X GET "$API_BASE_URL/anciens-designations?mois=6&annee=2026" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json")

echo "$AFTER" | jq '.data | {total_designes, designes: [.designes[] | {id, nom, est_designe, disponibilite}]}'
echo ""

TOTAL_AFTER=$(echo "$AFTER" | jq '.data.total_designes')
echo -e "${GREEN}✅ Total anciens désignés après: $TOTAL_AFTER${NC}\n"

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

# ============================================================================
# ÉTAPE 5: Vérification finale
# ============================================================================

echo -e "${YELLOW}ÉTAPE 5: Résumé${NC}"

if [ "$TOTAL_BEFORE" -gt "$TOTAL_AFTER" ]; then
  echo -e "${GREEN}✅ SUCCESS: L'ancien a bien été retiré${NC}"
  echo "   Avant: $TOTAL_BEFORE anciens"
  echo "   Après: $TOTAL_AFTER anciens"
  echo ""
  echo -e "${GREEN}🎉 TEST RÉUSSI${NC}\n"
else
  echo -e "${RED}❌ ERREUR: L'ancien n'a pas été retiré${NC}"
  echo "   Avant: $TOTAL_BEFORE anciens"
  echo "   Après: $TOTAL_AFTER anciens"
  exit 1
fi

# ============================================================================
# TEST AVANCÉ: Retirer un ancien avec plusieurs désignations
# ============================================================================

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}🧪 TEST AVANCÉ: Plusieurs désignations${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"

echo -e "${YELLOW}Créant 2 désignations pour le même ancien (juin + juillet)...${NC}\n"

# Créer 2 désignations
DES1=$(curl -s -X POST "$API_BASE_URL/anciens-designations" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ancien_id": 5,
    "mois": 6,
    "annee": 2026,
    "jours_travail": null,
    "horaire_debut": null,
    "horaire_fin": null
  }' | jq -r '.data.id')

DES2=$(curl -s -X POST "$API_BASE_URL/anciens-designations" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ancien_id": 5,
    "mois": 7,
    "annee": 2026,
    "jours_travail": null,
    "horaire_debut": null,
    "horaire_fin": null
  }' | jq -r '.data.id')

echo -e "${GREEN}✅ Créé désignation 1 (ID: $DES1)${NC}"
echo -e "${GREEN}✅ Créé désignation 2 (ID: $DES2)${NC}\n"

echo -e "${YELLOW}Retirant la 1ère désignation...${NC}"
curl -s -X DELETE "$API_BASE_URL/anciens-designations/$DES1" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  > /dev/null

echo -e "${GREEN}✅ 1ère retirée${NC}"
echo -e "${YELLOW}L'ancien devrait TOUJOURS être en service (car il en a une autre)${NC}\n"

# Vérifier
STATE1=$(curl -s -X GET "$API_BASE_URL/anciens-designations?mois=7&annee=2026" \
  -H "Authorization: Bearer $BEARER_TOKEN" | \
  jq '.data.designes[] | select(.id == 5) | .est_designe' 2>/dev/null)

if [ "$STATE1" == "true" ]; then
  echo -e "${GREEN}✅ Correct: L'ancien est toujours de service${NC}\n"
else
  echo -e "${YELLOW}⚠️  L'ancien n'apparaît peut-être pas dans juillet${NC}\n"
fi

echo -e "${YELLOW}Retirant la 2ème désignation...${NC}"
curl -s -X DELETE "$API_BASE_URL/anciens-designations/$DES2" \
  -H "Authorization: Bearer $BEARER_TOKEN" \
  > /dev/null

echo -e "${GREEN}✅ 2ème retirée${NC}"
echo -e "${YELLOW}L'ancien devrait MAINTENANT ne plus être en service${NC}\n"

echo -e "${GREEN}🎉 TEST AVANCÉ COMPLÉTÉ${NC}\n"
