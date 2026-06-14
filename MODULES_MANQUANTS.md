# 📊 MODULES FRONTEND MANQUANTS - Résumé Simplifié

**Status**: Analyse Backend vs Frontend complétée  
**Date**: 20 mai 2026

---

## 🎯 EN UN COUP D'ŒIL

```
BACKEND                          FRONTEND                    STATUS
════════════════════════════════════════════════════════════════════════

✅ Auth                    ←→   ✅ Login + ForgotPassword     🟢 OK
✅ Users                   ←→   ✅ Full CRUD UI               🟢 OK
✅ Signature               ←→   ✅ Ma Carte + Signer          🟢 OK
✅ Anciens                 ←→   ✅ Designation Management     🟢 OK
✅ Rapports                ←→   ✅ Rapport Secrétaire         🟢 OK
✅ Historique              ←→   ✅ History Display            🟢 OK

⚠️  Finances                ←→   ⚠️  Partial Screen            🟡 PARTIAL
                                   (basique, pas de rapports)

✅ Church CRUD             ←→   ❌ NO FRONTEND               🔴 MISSING
✅ Payment CRUD            ←→   ❌ NO FRONTEND (lectures ok)  🔴 MISSING
✅ Publication CRUD        ←→   ❌ NO FRONTEND               🔴 MISSING
⚠️  Cards (empty backend)  ←→   ❌ NO FRONTEND               🔴 NOT READY
```

---

## 🔴 CE QUI MANQUE (4 modules à créer)

### 1. **PUBLICATION MODULE** — CRÍTICO
L'API backend peut créer/modifier des publications, mais **l'app ne les affiche pas**.

```
Backend API:
✅ GET /v1/publications (afficher)
✅ GET /v1/publications/{id} (détails)
✅ ADMIN: POST/PUT/DELETE

Frontend:
❌ Aucune UI pour afficher les publications
❌ Les users ne voient pas les publications de leur église
```

**À créer:**
```
lib/features/publication/
├── PublicationEntity & PublicationModel
├── publications_provider.dart (Riverpod)
├── publications_list_screen.dart (afficher liste)
└── publication_detail_screen.dart (afficher détails)
```

---

### 2. **FINANCE REPORTS** — IMPORTANT
Le backend a les rapports, mais l'app n'en affiche que peu.

```
Backend API:
✅ GET /v1/rapports/finances/annuel
✅ GET /v1/rapports/finances/mois
✅ Données complètes avec montants par mois

Frontend:
⚠️ Existe: finances_screen.dart (basique)
❌ Manquent: Rapports annuel/mensuel
❌ Manquent: Graphiques
```

**À améliorer:**
- Ajouter onglets annuel/mensuel
- Afficher statistiques
- Ajouter graphiques

---

### 3. **CHURCH MANAGEMENT** — ADMIN DASHBOARD
Admin doit pouvoir gérer les églises, mais il n'y a pas de UI.

```
Backend API:
✅ GET /admin/churches (list)
✅ POST /admin/churches (create)
✅ PUT /admin/churches/{id} (update)
✅ DELETE /admin/churches/{id} (delete)
✅ POST /admin/churches/{id}/pastor (assign pastor)

Frontend:
❌ Aucune UI pour admin gérer les églises
```

**À créer:**
```
lib/features/churches/
├── ChurchEntity & ChurchModel
├── churches_provider.dart (Riverpod)
├── churches_list_screen.dart (admin view)
├── church_detail_screen.dart (edit)
└── create_church_dialog.dart (create)
```

---

### 4. **PAYMENT MANAGEMENT** — ADMIN DASHBOARD
Admin doit pouvoir gérer les paiements (refund, retry), mais il n'y a pas de UI.

```
Backend API:
✅ GET /admin/payments (list)
✅ GET /admin/payments/{id} (show)
✅ PUT /admin/payments/{id} (update)
✅ DELETE /admin/payments/{id} (refund)
✅ POST /admin/payments/campay-retry (retry)

Frontend:
❌ Aucune UI pour admin gérer les paiements
⚠️ (Les users voient les paiements via historique)
```

**À créer:**
```
lib/features/payment_management/
├── PaymentEntity & PaymentModel
├── payment_admin_provider.dart (Riverpod)
├── payment_admin_screen.dart (list)
├── payment_detail_screen.dart (details + refund)
```

---

## ⏳ PRIORITÉ RECOMMANDÉE

### Semaine 1 — CRUCIAL (User-facing)
```
1. 🔴 PUBLICATION Module — Users attendent
   Effort: 4-6 heures
   Impact: Utilisateurs peuvent lire publications

2. 🟡 FINANCE Reports — Trésoriers/Pasteurs attendent
   Effort: 3-4 heures
   Impact: Better financial visibility
```

### Semaine 2 — IMPORTANT (Admin dashboard)
```
3. 🟡 CHURCH Management — Admin doit gérer églises
   Effort: 6-8 heures
   Impact: Admin can manage churches

4. 🟡 PAYMENT Management — Admin doit gérer paiements
   Effort: 6-8 heures
   Impact: Admin can refund/retry payments
```

### Plus tard — OPTIONNEL
```
5. ⏳ Cards Management — Quand backend prêt
6. ⏳ Advanced Auth — MFA, Device Management, Security Alerts
```

---

## 📝 NOTES IMPORTANTES

### ✅ CE QUI FONCTIONNE BIEN
- Authentification: OK
- Gestion utilisateurs: OK
- Signatures (distance + presentiel): OK
- Anciens: OK
- Historique: OK

### ⚠️ CE QUI EST PARTIEL
- Finances: Écran existe mais basique
- Rapports: Secrétaire OK, finances manquent

### ❌ CE QUI MANQUE
- Publications: Complètement absent
- Churches: Admin can't manage
- Payments: Admin can't manage
- Cards: Backend not ready

---

## 🚀 COMMENCER RAPIDEMENT

Pour implémenter une feature manquante rapidement:

1. **Copier la structure** d'un module existant (ex: users)
2. **Adapter l'Entity** à la structure de données
3. **Créer les screens** (list + detail)
4. **Ajouter à app_router.dart** pour naviguer
5. **Tester avec l'API**

**Exemple simple** - Copier `users_screen.dart` et adapter pour `publications_list_screen.dart`.

---

## 📋 CHECKLIST À FAIRE

- [ ] Publication Module (PRIORITÉ 1)
- [ ] Finance Reports (PRIORITÉ 1)
- [ ] Church Management (PRIORITÉ 2)
- [ ] Payment Management (PRIORITÉ 2)
- [ ] Test tout ensemble
- [ ] Update routing (app_router.dart)

---

**Auteur**: Analysis Backend vs Frontend  
**Prochaine étape**: Implémenter les modules manquants
