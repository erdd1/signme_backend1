# API — Anciens Désignation (Nouvelle Fonctionnalité)

## Vue d'ensemble

La nouvelle fonctionnalité permet au pasteur de désigner des anciens de service avec des jours et horaires spécifiques.

### Règles métier implémentées:

1. **Période de désignation**: Le pasteur peut seulement désigner un ancien pour:
   - Le mois actuel
   - Le mois prochain
   - **PAS** les mois passés
   - **PAS** 2+ mois à l'avance

2. **Jours et horaires** (optionnels):
   - Si définis: L'ancien ne peut signer **UNIQUEMENT** ces jours et heures
   - Si vides/null: L'ancien peut signer **24/7 tous les jours**
   - Un ancien sans contrainte doit avoir `jours_travail: null` et `horaire_debut/fin: null`

3. **Rattrapage**: Un ancien désigné peut signer les **mois passés** (rattrapage)

4. **Retrait automatique**: Le dernier jour du mois de service à 23h59, l'ancien est automatiquement retiré du statut `est_de_service`

5. **Gestion manuelle**: Le pasteur peut ajouter/retirer des anciens **à tout moment** pendant le mois

---

## Endpoints API

### 1. Liste des anciens (désignés + non-désignés)

```http
GET /api/v1/anciens-designations?mois=6&annee=2026&church_id=1
Authorization: Bearer <token>
```

**Paramètres:**
- `mois` (required): 1-12
- `annee` (required): ex: 2026
- `church_id` (optional): Pour les admins (filtrer par église)

**Réponse 200 OK:**
```json
{
  "success": true,
  "message": "Désignations récupérées avec succès.",
  "data": {
    "mois": 6,
    "annee": 2026,
    "eglise_id": 1,
    "designes": [
      {
        "id": 5,
        "nom": "Jean Dupont",
        "photoUrl": "https://...",
        "telephone": "+237....",
        "est_designe": true,
        "jours_travail": [1, 2, 3, 4, 5],  // null = 24/7
        "horaire_debut": "08:00",          // null = 00:00
        "horaire_fin": "18:00",            // null = 23:59
        "disponibilite": {
          "jours": "Lundi, Mardi, Mercredi, Jeudi, Vendredi",
          "horaires": "08:00 - 18:00",
          "resume": "Disponible: Lundi, Mardi, Mercredi, Jeudi, Vendredi (08:00 - 18:00)"
        }
      }
    ],
    "non_designes": [
      {
        "id": 6,
        "nom": "Marie Durand",
        "photoUrl": "https://...",
        "telephone": "+237....",
        "est_designe": false
      }
    ],
    "total_designes": 1
  }
}
```

---

### 2. Désigner un ancien de service

```http
POST /api/v1/anciens-designations
Authorization: Bearer <token>
Content-Type: application/json
```

**Body (minimal):**
```json
{
  "ancien_id": 5,
  "mois": 6,
  "annee": 2026,
  "jours_travail": null,
  "horaire_debut": null,
  "horaire_fin": null
}
```

**Body (avec jours/horaires spécifiques):**
```json
{
  "ancien_id": 5,
  "mois": 6,
  "annee": 2026,
  "jours_travail": [1, 2, 3, 4, 5],  // Lundi-Vendredi
  "horaire_debut": "08:00",
  "horaire_fin": "18:00"
}
```

**Jours de la semaine:**
- 1 = Lundi
- 2 = Mardi
- 3 = Mercredi
- 4 = Jeudi
- 5 = Vendredi
- 6 = Samedi
- 7 = Dimanche

**Réponse 201 Created:**
```json
{
  "success": true,
  "message": "Ancien désigné de service avec succès.",
  "data": {
    "id": 42,
    "ancienId": 5,
    "ancienNom": "Jean Dupont",
    "egliseId": 1,
    "mois": 6,
    "annee": 2026,
    "jours_travail": [1, 2, 3, 4, 5],
    "horaire_debut": "08:00",
    "horaire_fin": "18:00",
    "date_fin_service": "2026-06-30 23:59:59",
    "designePar": 2,
    "designeParNom": "Pasteur Marchal",
    "created_at": "2026-04-25T10:30:00Z",
    "updated_at": "2026-04-25T10:30:00Z"
  }
}
```

**Erreurs possibles:**

- **400 Bad Request**: Période invalide
  ```json
  {
    "success": false,
    "message": "Seuls le mois actuel et le mois prochain sont autorisés pour la désignation."
  }
  ```

- **422 Unprocessable Entity**: Jours/horaires invalides
  ```json
  {
    "success": false,
    "message": "Erreur de validation",
    "errors": {
      "jours_travail.0": ["Chaque jour doit être entre 1 et 7"],
      "horaire_debut": ["L'horaire début doit être au format HH:MM"]
    }
  }
  ```

- **409 Conflict**: Ancien déjà désigné
  ```json
  {
    "success": false,
    "message": "Cet ancien est déjà désigné de service pour 6/2026."
  }
  ```

---

### 3. Retirer un ancien de service

```http
DELETE /api/v1/anciens-designations/42
Authorization: Bearer <token>
```

**Réponse 204 No Content:**
```
(vide)
```

Le pasteur reçoit le message: `"Désignation retirée avec succès. L'ancien n'est plus de service."`

---

### 4. Synchroniser les flags `est_de_service`

```http
POST /api/v1/anciens-designations/sync
Authorization: Bearer <token>
Content-Type: application/json
```

**Body:**
```json
{
  "church_id": 1  // optional, pour les admins
}
```

**Réponse 200 OK:**
```json
{
  "success": true,
  "message": "Flags est_de_service synchronisés avec succès.",
  "data": null
}
```

---

## Flux complet d'exemple

### Juin 2026 (mois actuel)

**Étape 1: Le pasteur consulte les anciens disponibles pour juin**
```http
GET /api/v1/anciens-designations?mois=6&annee=2026
```

**Étape 2: Le pasteur désigne Jean pour juin (lundi-vendredi, 08:00-18:00)**
```http
POST /api/v1/anciens-designations
{
  "ancien_id": 5,
  "mois": 6,
  "annee": 2026,
  "jours_travail": [1, 2, 3, 4, 5],
  "horaire_debut": "08:00",
  "horaire_fin": "18:00"
}
```

**Étape 3: Jean signe maintenant (jeudi 12 juin 2026, 14:00)**
- ✅ Peut signer (jeudi = jour 4, 14:00 est entre 08:00-18:00)

**Étape 4: Jean essaie de signer samedi 13 juin à 10:00**
- ❌ Erreur: "Vous ne pouvez pas signer à cette heure. Disponible: Lundi, Mardi, Mercredi, Jeudi, Vendredi (08:00 - 18:00)"

**Étape 5: Le 30 juin à 23h59:59**
- L'ancien est automatiquement retiré (Job `RetireAnciensDeServiceExpires`)
- `est_de_service` devient `false` automatiquement

**Étape 6: Le pasteur peut désigner le même ancien pour juillet**
```http
POST /api/v1/anciens-designations
{
  "ancien_id": 5,
  "mois": 7,
  "annee": 2026,
  "jours_travail": null,     // 24/7 ce mois-ci
  "horaire_debut": null,
  "horaire_fin": null
}
```

---

## Validations côté API

### Lors de la désignation:

```
✅ Pasteur peut désigner ancien pour MOIS ACTUEL
✅ Pasteur peut désigner ancien pour MOIS PROCHAIN
❌ Pasteur CANNOT désigner pour mois passé
❌ Pasteur CANNOT désigner pour 2+ mois à l'avance
✅ Jours/horaires doivent être valides
✅ Un ancien ne peut être désigné qu'une fois par mois/église
```

### Lors de la signature en présentiel:

```
✅ Seuls ancien/pasteur/admin peuvent signer
✅ Ancien doit être désigné pour ce mois
✅ Si ancien a des jours/horaires: doit respecter
❌ Si ancien n'a pas ces jours/horaires: erreur
✅ Ancien peut signer mois passés (rattrapage)
❌ Ancien ne peut pas signer mois futurs
```

---

## Jobs et Scheduler

### `RetireAnciensDeServiceExpires`

**Exécution:** Quotidiennement à 01:00 du matin

**Action:** 
- Récupère toutes les désignations où `date_fin_service <= now()`
- Les supprime
- Met à jour `est_de_service = false` pour les anciens sans autre désignation active

**Configuration:** `routes/console.php`

```php
Schedule::job(new RetireAnciensDeServiceExpires())
    ->daily()
    ->at('01:00');
```

---

## Notes pour le développeur Flutter

1. **Lors de la création d'une désignation:**
   - Laisser `jours_travail`, `horaire_debut`, `horaire_fin` à `null` pour 24/7
   - Ou fournir un array de jours (1-7) et des horaires valides (HH:MM)

2. **Lors de la consultation des anciens disponibles:**
   - La réponse inclut `disponibilite` avec le résumé lisible

3. **Lors d'une signature présentielle:**
   - L'API valide automatiquement les jours/horaires
   - Si l'ancien ne peut pas signer: erreur 400 avec le motif

4. **UI Recommendation:**
   - Afficher la disponibilité en gros dans l'écran de sélection
   - Afficher un warning si l'ancien essaie de signer hors de ses horaires
