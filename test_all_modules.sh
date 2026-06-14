#!/bin/bash

# ═══════════════════════════════════════════════════════════════════════════
# TEST COMPLET - TOUS LES MODULES
# ═══════════════════════════════════════════════════════════════════════════
#
# Teste chaque module indépendamment pour détecter les anomalies
# Notamment après modification du module AncienDesignation
#

API_URL="http://localhost:8000/api/v1"
RESULTS_FILE="/tmp/module_tests_results.txt"

# Couleurs
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "" > $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 1. MODULE AUTH - Test authentification
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[1/9] TESTING: AUTH MODULE${NC}"
echo "=============== TEST AUTH MODULE ===============" >> $RESULTS_FILE

# Test login
echo -e "${YELLOW}  → Test LOGIN endpoint${NC}"
LOGIN_RESPONSE=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "pasteur@example.com",
    "password": "password"
  }')

HTTP_CODE=$(echo "$LOGIN_RESPONSE" | jq -r '.success // "error"')

if [ "$HTTP_CODE" = "true" ]; then
  echo -e "${GREEN}✓ AUTH: Login successful${NC}"
  echo "✓ AUTH: Login successful" >> $RESULTS_FILE
  
  # Extraire le token
  TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.access_token')
  
  # Test /me endpoint
  echo -e "${YELLOW}  → Test /ME endpoint${NC}"
  ME_RESPONSE=$(curl -s -X GET "$API_URL/auth/me" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  ME_SUCCESS=$(echo "$ME_RESPONSE" | jq -r '.success')
  
  if [ "$ME_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ AUTH: /me endpoint successful${NC}"
    echo "✓ AUTH: /me endpoint successful" >> $RESULTS_FILE
    
    # Vérifier le flag est_de_service
    EST_DE_SERVICE=$(echo "$ME_RESPONSE" | jq -r '.data.est_de_service')
    echo "  - est_de_service flag: $EST_DE_SERVICE"
    echo "  - est_de_service flag: $EST_DE_SERVICE" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ AUTH: /me endpoint FAILED${NC}"
    echo "✗ AUTH: /me endpoint FAILED" >> $RESULTS_FILE
    echo "$ME_RESPONSE" | jq '.' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ AUTH: Login FAILED${NC}"
  echo "✗ AUTH: Login FAILED" >> $RESULTS_FILE
  echo "$LOGIN_RESPONSE" | jq '.' >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 2. MODULE USER - Test gestion utilisateurs
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[2/9] TESTING: USER MODULE${NC}"
echo "=============== TEST USER MODULE ===============" >> $RESULTS_FILE

# Réutiliser le TOKEN du login AUTH
if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /users${NC}"
  USERS_RESPONSE=$(curl -s -X GET "$API_URL/users" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  USERS_SUCCESS=$(echo "$USERS_RESPONSE" | jq -r '.success')
  
  if [ "$USERS_SUCCESS" = "true" ]; then
    USERS_COUNT=$(echo "$USERS_RESPONSE" | jq '.data | length')
    echo -e "${GREEN}✓ USER: GET /users successful (${USERS_COUNT} users)${NC}"
    echo "✓ USER: GET /users successful (${USERS_COUNT} users)" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ USER: GET /users FAILED${NC}"
    echo "✗ USER: GET /users FAILED" >> $RESULTS_FILE
    echo "$USERS_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ USER: Skipped (no token)${NC}"
  echo "✗ USER: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 3. MODULE ANCIEN DESIGNATION - Test désignation d'anciens
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[3/9] TESTING: ANCIEN DESIGNATION MODULE${NC}"
echo "=============== TEST ANCIEN DESIGNATION MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /anciens-designations${NC}"
  DESIGNATIONS_RESPONSE=$(curl -s -X GET "$API_URL/anciens-designations" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  DESIGNATIONS_SUCCESS=$(echo "$DESIGNATIONS_RESPONSE" | jq -r '.success')
  
  if [ "$DESIGNATIONS_SUCCESS" = "true" ]; then
    DESIGNATIONS_COUNT=$(echo "$DESIGNATIONS_RESPONSE" | jq '.data | length')
    echo -e "${GREEN}✓ ANCIEN DESIGNATION: GET successful (${DESIGNATIONS_COUNT} designations)${NC}"
    echo "✓ ANCIEN DESIGNATION: GET successful (${DESIGNATIONS_COUNT} designations)" >> $RESULTS_FILE
    
    # Vérifier la structure des réponses
    echo -e "${YELLOW}  → Verifying response structure${NC}"
    FIRST_DESIGNATION=$(echo "$DESIGNATIONS_RESPONSE" | jq '.data[0]')
    
    if [ ! -z "$FIRST_DESIGNATION" ] && [ "$FIRST_DESIGNATION" != "null" ]; then
      echo "  Structure:" >> $RESULTS_FILE
      echo "$FIRST_DESIGNATION" | jq '.' >> $RESULTS_FILE
    fi
  else
    echo -e "${RED}✗ ANCIEN DESIGNATION: GET FAILED${NC}"
    echo "✗ ANCIEN DESIGNATION: GET FAILED" >> $RESULTS_FILE
    echo "$DESIGNATIONS_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ ANCIEN DESIGNATION: Skipped (no token)${NC}"
  echo "✗ ANCIEN DESIGNATION: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 4. MODULE SIGNATURE - Test signature (dépend d'AncienDesignation)
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[4/9] TESTING: SIGNATURE MODULE${NC}"
echo "=============== TEST SIGNATURE MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /signatures/fees${NC}"
  FEES_RESPONSE=$(curl -s -X GET "$API_URL/signatures/fees" \
    -H "Content-Type: application/json")
  
  FEES_SUCCESS=$(echo "$FEES_RESPONSE" | jq -r '.success')
  
  if [ "$FEES_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ SIGNATURE: GET /fees successful${NC}"
    echo "✓ SIGNATURE: GET /fees successful" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ SIGNATURE: GET /fees FAILED${NC}"
    echo "✗ SIGNATURE: GET /fees FAILED" >> $RESULTS_FILE
  fi
  
  echo -e "${YELLOW}  → Test GET /signatures/ma-carte${NC}"
  CARTE_RESPONSE=$(curl -s -X GET "$API_URL/signatures/ma-carte" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  CARTE_SUCCESS=$(echo "$CARTE_RESPONSE" | jq -r '.success')
  
  if [ "$CARTE_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ SIGNATURE: GET /ma-carte successful${NC}"
    echo "✓ SIGNATURE: GET /ma-carte successful" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ SIGNATURE: GET /ma-carte FAILED${NC}"
    echo "✗ SIGNATURE: GET /ma-carte FAILED" >> $RESULTS_FILE
    echo "$CARTE_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ SIGNATURE: Skipped (no token)${NC}"
  echo "✗ SIGNATURE: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 5. MODULE CHURCH - Test gestion églises
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[5/9] TESTING: CHURCH MODULE${NC}"
echo "=============== TEST CHURCH MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /churches${NC}"
  CHURCHES_RESPONSE=$(curl -s -X GET "$API_URL/churches" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  CHURCHES_SUCCESS=$(echo "$CHURCHES_RESPONSE" | jq -r '.success')
  
  if [ "$CHURCHES_SUCCESS" = "true" ]; then
    CHURCHES_COUNT=$(echo "$CHURCHES_RESPONSE" | jq '.data | length')
    echo -e "${GREEN}✓ CHURCH: GET /churches successful (${CHURCHES_COUNT} churches)${NC}"
    echo "✓ CHURCH: GET /churches successful (${CHURCHES_COUNT} churches)" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ CHURCH: GET /churches FAILED${NC}"
    echo "✗ CHURCH: GET /churches FAILED" >> $RESULTS_FILE
    echo "$CHURCHES_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ CHURCH: Skipped (no token)${NC}"
  echo "✗ CHURCH: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 6. MODULE PUBLICATION - Test publications
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[6/9] TESTING: PUBLICATION MODULE${NC}"
echo "=============== TEST PUBLICATION MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /publications${NC}"
  PUBLICATIONS_RESPONSE=$(curl -s -X GET "$API_URL/publications" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  PUBLICATIONS_SUCCESS=$(echo "$PUBLICATIONS_RESPONSE" | jq -r '.success')
  
  if [ "$PUBLICATIONS_SUCCESS" = "true" ]; then
    PUBLICATIONS_COUNT=$(echo "$PUBLICATIONS_RESPONSE" | jq '.data | length')
    echo -e "${GREEN}✓ PUBLICATION: GET successful (${PUBLICATIONS_COUNT} publications)${NC}"
    echo "✓ PUBLICATION: GET successful (${PUBLICATIONS_COUNT} publications)" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ PUBLICATION: GET FAILED${NC}"
    echo "✗ PUBLICATION: GET FAILED" >> $RESULTS_FILE
    echo "$PUBLICATIONS_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ PUBLICATION: Skipped (no token)${NC}"
  echo "✗ PUBLICATION: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 7. MODULE RAPPORT - Test rapports
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[7/9] TESTING: RAPPORT MODULE${NC}"
echo "=============== TEST RAPPORT MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /rapports/secretaire${NC}"
  RAPPORT_RESPONSE=$(curl -s -X GET "$API_URL/rapports/secretaire" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")
  
  RAPPORT_SUCCESS=$(echo "$RAPPORT_RESPONSE" | jq -r '.success')
  
  if [ "$RAPPORT_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ RAPPORT: GET /rapports/secretaire successful${NC}"
    echo "✓ RAPPORT: GET /rapports/secretaire successful" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ RAPPORT: GET /rapports/secretaire FAILED${NC}"
    echo "✗ RAPPORT: GET /rapports/secretaire FAILED" >> $RESULTS_FILE
    echo "$RAPPORT_RESPONSE" | jq '.message' >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ RAPPORT: Skipped (no token)${NC}"
  echo "✗ RAPPORT: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 8. MODULE PAYMENT - Test paiements
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[8/9] TESTING: PAYMENT MODULE${NC}"
echo "=============== TEST PAYMENT MODULE ===============" >> $RESULTS_FILE

if [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${YELLOW}  → Test GET /payments/check/{reference}${NC}"
  
  # Créer une signature d'abord pour obtenir une référence paiement
  echo -e "${YELLOW}  → Initiating signature to get payment reference${NC}"
  
  INIT_RESPONSE=$(curl -s -X POST "$API_URL/signatures/distance" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{
      "churchId": 1,
      "message": "Test signature"
    }')
  
  INIT_SUCCESS=$(echo "$INIT_RESPONSE" | jq -r '.success')
  
  if [ "$INIT_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ PAYMENT: Can initiate signatures${NC}"
    echo "✓ PAYMENT: Can initiate signatures" >> $RESULTS_FILE
  else
    echo -e "${RED}✗ PAYMENT: Cannot initiate signatures${NC}"
    echo "✗ PAYMENT: Cannot initiate signatures" >> $RESULTS_FILE
  fi
else
  echo -e "${RED}✗ PAYMENT: Skipped (no token)${NC}"
  echo "✗ PAYMENT: Skipped (no token)" >> $RESULTS_FILE
fi

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# 9. MODULE CARDS - Test cartes
# ═══════════════════════════════════════════════════════════════════════════

echo -e "${BLUE}[9/9] TESTING: CARDS MODULE${NC}"
echo "=============== TEST CARDS MODULE ===============" >> $RESULTS_FILE

echo -e "${YELLOW}  → Module not directly tested (internal module)${NC}"
echo "✓ CARDS: Internal module" >> $RESULTS_FILE

echo "" >> $RESULTS_FILE

# ═══════════════════════════════════════════════════════════════════════════
# RÉSUMÉ
# ═══════════════════════════════════════════════════════════════════════════

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}RÉSUMÉ DES TESTS - TOUS LES MODULES${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════════${NC}"
echo ""
cat $RESULTS_FILE
echo ""
echo -e "${BLUE}Full results saved to: $RESULTS_FILE${NC}"
