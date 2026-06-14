# 🔐 PHASE 3 - NOTIFICATIONS & AUDIT LOGS

## Vue d'Ensemble

Phase 3 complète les **30 principes de sécurité** avec :
- 🔔 **SecurityNotificationService** : Alertes email en temps réel
- 📊 **AuditLogService** : Historique complet des événements
- 🛡️ **Admin Security Controls** : Gestion centralisée
- ⚙️ **Advanced Detection** : Patterns de sécurité

**Status**: ✅ COMPLÈTE - 30/30 principes implémentés

---

## 📋 Services Créés

### 1. SecurityNotificationService

**Fichier**: `app/Modules/Auth/Application/Services/SecurityNotificationService.php`

**Méthodes Principales**:

```php
// Notifier d'une nouvelle connexion
notifyNewLogin(User $user, string $ipAddress, ?string $deviceName, ?string $location)

// Notifier d'un changement de password
notifyPasswordChange(User $user)

// Notifier d'un nouvel appareil connecté
notifyNewDevice(User $user, string $deviceName, string $ipAddress, ?string $location)

// Notifier d'une activité suspecte
notifySuspiciousActivity(User $user, string $activityType, string $riskLevel, ...)

// Notifier d'une tentative MFA échouée
notifyMFAAttempt(User $user, bool $success, ?string $ipAddress)

// Mettre à jour les préférences
updateNotificationPreferences(User $user, bool $enabled)
```

**Notifications Envoyées**:
- ✅ New login detected
- ✅ Password changed
- ✅ New device connected
- ✅ Suspicious activity alert
- ✅ MFA attempts (success/failure)

---

### 2. AuditLogService

**Fichier**: `app/Modules/Auth/Application/Services/AuditLogService.php`

**Méthodes Principales**:

```php
// Obtenir l'audit trail complet
getUserAuditTrail(User $user, int $days = 30, int $limit = 100)

// Obtenir les connexions récentes
getRecentLogins(User $user, int $limit = 20)

// Obtenir les tentatives échouées
getFailedAttempts(User $user, int $days = 7, int $limit = 50)

// Obtenir l'historique des password changes
getPasswordChanges(User $user, int $limit = 20)

// Obtenir l'activité des tokens
getTokenActivity(User $user, int $limit = 50)

// Admin: tous les logs de sécurité
getAllSecurityLogs(int $days = 30, int $limit = 500)

// Admin: toutes les alertes
getSecurityAlerts(int $hours = 24, int $limit = 100)

// Exporter en CSV
exportLogsAsCSV(User $user, int $days = 30)
```

---

## 🔌 Endpoints API Phase 3

### Notifications (Utilisateur)

```
GET    /api/v1/auth/security/notification-preferences
POST   /api/v1/auth/security/notification-preferences
```

**Example**:
```bash
# Récupérer les préférences
curl -X GET http://localhost/api/v1/auth/security/notification-preferences \
  -H "Authorization: Bearer $TOKEN"

# Mettre à jour
curl -X POST http://localhost/api/v1/auth/security/notification-preferences \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"enabled": true}'
```

---

### Audit Logs (Utilisateur)

```
GET    /api/v1/auth/audit/trail
GET    /api/v1/auth/audit/logins
GET    /api/v1/auth/audit/failed-attempts
GET    /api/v1/auth/audit/password-changes
GET    /api/v1/auth/audit/token-activity
GET    /api/v1/auth/audit/trail/export
```

**Examples**:
```bash
# Audit trail (30 derniers jours, max 100)
curl -X GET 'http://localhost/api/v1/auth/audit/trail?days=30&limit=100' \
  -H "Authorization: Bearer $TOKEN"

# Connexions récentes
curl -X GET 'http://localhost/api/v1/auth/audit/logins?limit=20' \
  -H "Authorization: Bearer $TOKEN"

# Tentatives échouées (7 derniers jours)
curl -X GET 'http://localhost/api/v1/auth/audit/failed-attempts?days=7&limit=50' \
  -H "Authorization: Bearer $TOKEN"

# Changements de password
curl -X GET 'http://localhost/api/v1/auth/audit/password-changes?limit=20' \
  -H "Authorization: Bearer $TOKEN"

# Activité des tokens
curl -X GET 'http://localhost/api/v1/auth/audit/token-activity?limit=50' \
  -H "Authorization: Bearer $TOKEN"

# Exporter en CSV
curl -X GET 'http://localhost/api/v1/auth/audit/trail/export?days=30' \
  -H "Authorization: Bearer $TOKEN" \
  --output audit.csv
```

**Response Format**:
```json
{
  "success": true,
  "message": "Événement(s) d'audit récupéré(s)",
  "data": [
    {
      "id": 123,
      "action": "login",
      "status": "success",
      "timestamp": "2026-05-03 14:30:45",
      "ip_address": "192.168.1.1",
      "device_id": "mobile_12345",
      "details": {...}
    }
  ]
}
```

---

### Admin Security Controls

```
GET    /api/v1/auth/admin/security-logs
GET    /api/v1/auth/admin/security-alerts
POST   /api/v1/auth/admin/enforce-mfa/{user_id}
```

**Examples**:
```bash
# Tous les logs de sécurité (admin only)
curl -X GET 'http://localhost/api/v1/auth/admin/security-logs?days=30&limit=500' \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Toutes les alertes (admin only)
curl -X GET 'http://localhost/api/v1/auth/admin/security-alerts?hours=24&limit=100' \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Forcer MFA pour un utilisateur
curl -X POST 'http://localhost/api/v1/auth/admin/enforce-mfa/42' \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

---

## 🔔 Types de Notifications

### 1. New Login Notification
```
Subject: Nouvelle connexion à votre compte - EEC SignCard
Content:
- IP Address
- Timestamp
- Device Name (if provided)
- Location (if available)
```

### 2. Password Change Notification
```
Subject: Votre mot de passe a été modifié - EEC SignCard
Content:
- Confirmation du changement
- Timestamp
- Alert si non reconnu
```

### 3. New Device Notification
```
Subject: Nouvel appareil connecté à votre compte - EEC SignCard
Content:
- Device name
- IP Address
- Location
- Action link to approve/reject
```

### 4. Suspicious Activity Alert
```
Subject: 🚨 Alerte de sécurité - EEC SignCard
Content:
- Activity type (multiple IPs, country change, brute force)
- Risk level
- IP & location
- Action link to review
```

### 5. MFA Attempt Notification
```
Subject: ✅/❌ Authentification à deux facteurs - EEC SignCard
Content:
- Success or failed status
- Timestamp
- IP if failed
- Warning if multiple failures
```

---

## 📊 Structure des Logs de Sécurité

### Champs Enregistrés

```json
{
  "id": "integer",
  "user_id": "integer",
  "action": "login|logout|failed_login|password_reset|token_refresh|mfa_enabled|etc",
  "status": "success|failed|blocked|alert",
  "ip_address": "string",
  "user_agent": "string",
  "device_id": "string",
  "details": {
    "reason": "invalid_credentials|account_inactive|etc",
    "device_name": "string",
    "location": "string",
    "extra_data": {}
  },
  "created_at": "timestamp"
}
```

### Actions Tracées

- `login` - Successful login
- `logout` - User logout
- `failed_login` - Failed login attempt
- `token_refresh` - Token refresh
- `password_reset` - Password reset via email
- `password_changed` - Password change by user
- `mfa_enabled` - MFA enabled
- `mfa_disabled` - MFA disabled
- `mfa_verification_failed` - Failed MFA attempt
- `email_verified` - Email verified
- `device_registered` - New device registered
- `suspicious_activity` - Suspicious activity detected
- `account_blocked` - Account temporarily blocked

---

## ⚙️ Configuration Phase 3

**File**: `config/security.php`

```php
'notifications' => [
    'enabled'                   => true,
    'notify_new_login'          => true,
    'notify_password_change'    => true,
    'notify_new_device'         => true,
    'notify_suspicious_activity' => true,
    'notify_mfa_change'         => true,
],

'audit_logging' => [
    'enabled'           => true,
    'retention_days'    => 90,  // Keep logs for 90 days
    'archive_to_s3'     => false, // Future: Archive to S3
],
```

---

## 🔐 Intégration avec AuthService

### Login Flow Amélioré

```
1. Rate limit check
2. Credential verification
3. Account active check
4. MFA verification (Phase 2)
5. Suspicious activity analysis (Phase 2)
6. Device registration (Phase 2)
7. Create tokens
8. Log successful login
9. ✨ Send notification email (Phase 3)
10. Return tokens
```

---

## 📝 Form Requests

### UpdateNotificationPreferencesRequest
```php
// Validation
'enabled' => 'required|boolean'
```

---

## 🗂️ Fichiers Créés / Modifiés

### Créés (Phase 3)
- ✅ `SecurityNotificationService.php`
- ✅ `AuditLogService.php`
- ✅ `UpdateNotificationPreferencesRequest.php`

### Modifiés (Phase 3)
- ✅ `AuthController.php` (+10 endpoints)
- ✅ `AuthService.php` (intégration notifications)
- ✅ `AppServiceProvider.php` (service registration)
- ✅ `routes/api.php` (Phase 3 routes)
- ✅ `config/security.php` (Phase 3 config)

---

## 🧪 Test Scenario

### Scenario: Login with Notifications

```bash
# 1. Connexion utilisateur
curl -X POST http://localhost/api/v1/auth/login \
  -d '{
    "email": "user@example.com",
    "password": "password123",
    "device_id": "mobile_device_001"
  }'

# 2. Récupérer l'audit trail
curl -X GET http://localhost/api/v1/auth/audit/trail \
  -H "Authorization: Bearer $TOKEN"

# 3. Vérifier les préférences
curl -X GET http://localhost/api/v1/auth/security/notification-preferences \
  -H "Authorization: Bearer $TOKEN"

# 4. Récupérer les logins récents
curl -X GET http://localhost/api/v1/auth/audit/logins \
  -H "Authorization: Bearer $TOKEN"

# 5. Admin: Voir tous les logs
curl -X GET http://localhost/api/v1/auth/admin/security-logs?hours=1 \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

---

## 📊 Statistiques Phase 3

| Catégorie | Nombre | Status |
|-----------|--------|--------|
| Services | 2 | ✅ Created |
| Endpoints | 10 | ✅ Created |
| Form Requests | 1 | ✅ Created |
| Configuration | 1 | ✅ Updated |
| **Total** | **14** | **✅ 100%** |

---

## 🎯 Résumé Complet (Phase 1-3)

### Phase 1 : Foundation (6 principes)
- ✅ Access token expiration (30 min)
- ✅ Refresh token rotation (7 days)
- ✅ Rate limiting (3 tiers)
- ✅ Temporary blocking (3 tiers)
- ✅ Centralized logging (sanitized)
- ✅ AuthService abstraction

### Phase 2 : Advanced Auth (20 principes)
- ✅ MFA/OTP email (10 min expiry)
- ✅ Email verification
- ✅ Device management (4 endpoints)
- ✅ Suspicious activity detection
- ✅ Security headers (8 headers)
- ✅ Device sessions
- ✅ OTP codes management
- ✅ Backup codes

### Phase 3 : Operations (4 principes)
- ✅ Security notifications (5 types)
- ✅ Audit logs (7 queries)
- ✅ Admin controls (3 endpoints)
- ✅ CSV export

---

## 🚀 Next Steps (Future Phases)

### Phase 4 - Advanced Features
- [ ] GeoIP Integration (MaxMind)
- [ ] TOTP (Google Authenticator)
- [ ] IP Whitelisting
- [ ] Session device fingerprinting
- [ ] Device approval workflow

### Phase 5 - Compliance
- [ ] GDPR compliance (data export, deletion)
- [ ] SOC 2 audit logs
- [ ] PCI compliance for payment data
- [ ] Encryption at rest for sensitive fields

### Phase 6 - Advanced Analytics
- [ ] Security dashboard
- [ ] Threat intelligence
- [ ] ML-based anomaly detection
- [ ] Security incidents database

---

## 📞 Support

For questions about Phase 3 implementation, refer to:
- `ARCHITECTURE.md` - System design
- `CONVENTIONS.md` - Code conventions
- `config/security.php` - Configuration
- Phase 1 & 2 documentation in this project

