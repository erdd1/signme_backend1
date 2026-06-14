# 💳 Système de Paiement DDD - EEC SignCard API

## 📋 Vue d'ensemble

Implémentation complète et robuste d'un système de paiement Campay suivant l'architecture **Domain-Driven Design (DDD)** modulaire.

- **Passerelle** : Campay (Orange Money, MTN Mobile Money)
- **État du paiement** : pending → completed OU pending → failed
- **Auto-update** : Webhooks + Polling (fallback)
- **Signatures** : Automatiquement créées/mises à jour lors du succès du paiement

---

## 🏗️ Architecture Implémentation

### 📁 Structure des fichiers

```
app/Modules/Payment/
├── Domain/                                    # Couche métier pure
│   ├── PaymentEntity.php                     # Entité avec logique métier
│   ├── PaymentRepositoryInterface.php        # Contrat d'accès aux données
│   ├── PaymentEvents.php                     # Événements de domaine
│   └── ValueObjects/
│       ├── Money.php                         # Value Object Montant
│       └── PaymentStatus.php                 # Value Object Statut
│
├── Application/                               # Orchestration
│   ├── PaymentService.php                    # Service applicatif (logique complexe)
│   ├── PaymentServiceProvider.php            # DI Container
│   ├── Jobs/
│   │   └── CheckPaymentStatusJob.php         # Polling en cas d'échec webhook
│   └── DTOs/
│       ├── InitiatePaymentDTO.php            # DTO Initiation
│       └── ProcessPaymentDTO.php             # DTO Webhook
│
├── Infrastructure/                            # Détails techniques
│   ├── PaymentRepository.php                 # Implémentation Repository
│   ├── ExternalServices/
│   │   └── CampayGateway.php                 # Intégration Campay
│   └── PaymentModel.php                      # Modèle Eloquent (si nécessaire)
│
└── Http/                                     # Interface API
    ├── PaymentController.php                 # Endpoints HTTP
    ├── Requests/
    │   ├── InitiatePaymentRequest.php        # Validation initiation
    │   └── WebhookRequest.php                # Validation webhook
    └── Resources/
        └── PaymentResource.php               # Formatage réponse
```

---

## ⚙️ Configuration

### 1. Variables d'environnement (.env)

```env
# Campay Configuration
CAMPAY_API_KEY=your_campay_api_key_here
CAMPAY_BASE_URL=https://demo.campay.net/api/collect/
CAMPAY_WEBHOOK_URL=https://votre-vps.com/api/v1/webhooks/campay

# Paiement
PAYMENT_DEFAULT_FEES=0
PAYMENT_GATEWAY=campay
```

### 2. Configuration locale (développement)

Pour tester les webhooks en développement, utilisez **ngrok** ou **Expose** :

```bash
# Exemple avec ngrok
ngrok http 8000

# La URL publique sera : https://xxxxx-yy-zzz.ngrok-free.dev
# À utiliser pour CAMPAY_WEBHOOK_URL
```

### 3. Configuration VPS (production)

```env
CAMPAY_WEBHOOK_URL=https://api.votre-domaine.com/api/v1/webhooks/campay
```

---

## 🚀 Flux d'utilisation

### Étape 1 : Initier un paiement

**Endpoint** : `POST /api/v1/payments/initiate`

**Auth** : Bearer Token (Sanctum)

**Body** :
```json
{
  "paymentOperator": "ORANGE MONEY",
  "paymentNumber": "237678901234",
  "amountPerMonth": 1000,
  "monthsCount": 2,
  "fees": 100,
  "signatureType": "distance",
  "signatureIds": [1, 2]
}
```

**Réponse** :
```json
{
  "success": true,
  "message": "Paiement initié avec succès",
  "data": {
    "paymentId": 42,
    "paymentLink": "https://pay.campay.net/...",
    "amount": 2100,
    "currency": "XOF"
  }
}
```

### Étape 2 : Utilisateur paie

- Cliquer sur `paymentLink`
- Entrer le code USSD ou confirmer via l'app Campay
- Campay envoie une notification au **webhook**

### Étape 3 : Webhook traite le paiement

**Endpoint** : `POST /api/v1/webhooks/campay` (public)

Campay envoie automatiquement :
```json
{
  "reference": "CAM-xxxxx",
  "status": "SUCCESSFUL",
  "amount": 2100,
  "phone": "237678901234"
}
```

**Traitement** :
1. ✅ Paiement trouvé
2. ✅ Statut = `completed`
3. ✅ Signatures liées = `completed`
4. ✅ Événement déclenché

### Étape 4 : Récupérer le statut

**Endpoint** : `GET /api/v1/payments/{paymentId}`

**Réponse** :
```json
{
  "success": true,
  "data": {
    "id": 42,
    "userId": 5,
    "totalAmount": 2000,
    "fees": 100,
    "amountCharged": 2100,
    "status": "completed",
    "createdAt": "2026-04-24 10:30:00",
    "updatedAt": "2026-04-24 10:35:00"
  }
}
```

---

## 🔄 Gestion des erreurs

### Erreur lors de l'initiation

```json
{
  "success": false,
  "message": "Erreur Campay: ...",
  "errors": { ... }
}
```

### Paiement échoue

Campay envoie `status: "FAILED"` → Signatures = `failed` → Événement déclenché

### Webhook n'arrive jamais (fallback)

- **Job `CheckPaymentStatusJob`** s'exécute après 5 minutes
- Vérifie le statut auprès de Campay
- Met à jour localement si succès/échec

---

## 📊 Structure des données

### Table `payment_transactions`

```sql
id                  BIGINT PRIMARY KEY
userId              BIGINT FOREIGN KEY → users
churchId            BIGINT FOREIGN KEY → churches
paymentOperator     VARCHAR(50)        -- 'ORANGE MONEY', 'MTN MOBILE MONEY'
paymentNumber       VARCHAR(20)        -- '237678901234'
amountPerMonth      INT                -- 1000 XOF
monthsCount         TINYINT            -- 1 à 12
totalAmount         INT                -- amountPerMonth * monthsCount
fees                INT                -- Frais Campay
amountCharged       INT                -- totalAmount + fees
campay_reference    VARCHAR(255)       -- UNIQUE
status              ENUM('pending', 'completed', 'failed')
signatureType       ENUM('presentiel', 'distance')
campay_metadata     JSON               -- Réponse Campay brute
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

### Table `signature_payment` (pivot)

```sql
id                      BIGINT PRIMARY KEY
signature_id            BIGINT FOREIGN KEY → signatures
payment_transaction_id  BIGINT FOREIGN KEY → payment_transactions
UNIQUE(signature_id, payment_transaction_id)
created_at              TIMESTAMP
```

---

## 🧪 Tests

### Test avec Postman/Insomnia

1. **Authentifiez-vous** :
   ```
   POST /api/v1/auth/login
   Body: { "email": "user@example.com", "password": "password" }
   ```
   Récupérez le token

2. **Initiez un paiement** :
   ```
   POST /api/v1/payments/initiate
   Headers: Authorization: Bearer {TOKEN}
   Body: {
     "paymentOperator": "ORANGE MONEY",
     "paymentNumber": "237678901234",
     "amountPerMonth": 1000,
     "monthsCount": 1,
     "fees": 100,
     "signatureType": "distance",
     "signatureIds": [1]
   }
   ```

3. **Simulez le webhook** :
   ```
   POST /api/v1/webhooks/campay
   Body: {
     "reference": "CAM-test-ref",
     "status": "SUCCESSFUL"
   }
   ```

4. **Vérifiez le statut** :
   ```
   GET /api/v1/payments/{paymentId}
   Headers: Authorization: Bearer {TOKEN}
   ```

---

## 🔐 Sécurité

- ✅ **Tokens Sanctum** pour les endpoints protégés
- ✅ **Validation stricte** des numéros de téléphone
- ✅ **Transactions DB** pour l'atomicité
- ✅ **Masquage des numéros** en réponse
- ✅ **Logs détaillés** pour audit
- ✅ **Exception handling** centralisé

---

## 📱 Exemple Flutter

```dart
final token = await getStoredToken();

// 1. Initier le paiement
final response = await http.post(
  Uri.parse('https://api.domain.com/api/v1/payments/initiate'),
  headers: {
    'Authorization': 'Bearer $token',
    'Content-Type': 'application/json',
  },
  body: jsonEncode({
    'paymentOperator': 'ORANGE MONEY',
    'paymentNumber': phoneNumber,
    'amountPerMonth': 1000,
    'monthsCount': 2,
    'fees': 100,
    'signatureType': 'distance',
    'signatureIds': [1, 2],
  }),
);

if (response.statusCode == 200) {
  final data = jsonDecode(response.body)['data'];
  // Ouvrir paymentLink dans WebView
  await launchUrl(Uri.parse(data['paymentLink']));
  
  // Attendre et vérifier le statut
  await checkPaymentStatus(data['paymentId'], token);
}
```

---

## 🐛 Débogage

### Activer les logs

```php
// Dans config/logging.php
'channels' => [
    'payments' => [
        'driver' => 'daily',
        'path' => storage_path('logs/payments.log'),
        'level' => 'debug',
    ],
],
```

### Logs importants

```bash
# Webhook reçu
grep "Campay Webhook received" storage/logs/laravel.log

# Paiement complété
grep "Payment completed" storage/logs/laravel.log

# Erreurs Campay
grep "Campay API Error" storage/logs/laravel.log
```

---

## 📞 Support

Pour toute question :
- Consultez les logs : `storage/logs/`
- Vérifiez la configuration `.env`
- Testez avec Postman
- Activez le mode debug pour plus de détails
