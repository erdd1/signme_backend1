# EEC SignCard API - Architecture DDD

## 📋 Vue d'ensemble

Application API REST Laravel **production-ready** pour gérer les signatures de cartes de sainte cène à distance et en présentiel.

- **Frontend**: Flutter
- **Backend**: Laravel 11+ (API REST)
- **Database**: MySQL 8+
- **Architecture**: Domain-Driven Design (DDD)

---

## 📁 Structure du Projet

```
app/
├── Core/                           # Couche tranversale
│   ├── Exceptions/                 # Gestion centralisée des exceptions
│   ├── Helpers/                    # Fonctions utilitaires globales
│   ├── Traits/                     # Traits réutilisables (ApiResponseTrait)
│   └── Services/                   # Services génériques (PaymentGatewayService)
│
├── Domain/                         # Couche métier (logique pure)
│   ├── Entities/                   # Représentation des objets métier
│   ├── Interfaces/                 # Contrats (repositories, services)
│   └── ValueObjects/               # Objets métier sans identité (Money)
│
├── Application/                    # Couche applicative
│   ├── Services/                   # Logique métier spécifique
│   └── DTOs/                       # Data Transfer Objects
│
├── Infrastructure/                 # Couche infrastructure
│   ├── Persistence/                # Configuration de persistance
│   ├── Repositories/               # Implémentation des repositories
│   └── ExternalServices/           # Intégration APIs externes
│
├── Modules/                        # Modules métier (séparation par feature)
│   ├── Auth/                       # Authentification
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Http/
│   │
│   ├── User/                       # Gestion des utilisateurs
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Http/
│   │
│   ├── Cards/                      # Gestion des cartes
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Http/
│   │
│   ├── Signature/                  # Gestion des signatures
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Http/
│   │
│   └── Payment/                    # Gestion des paiements
│       ├── Domain/
│       ├── Application/
│       ├── Infrastructure/
│       └── Http/
│
├── Http/                           # Couche HTTP
│   ├── Controllers/                # Controllers API
│   ├── Requests/                   # Form Requests (validation)
│   └── Middleware/                 # Middlewares personnalisés
│
├── Policies/                       # Policies d'autorisation
├── Jobs/                           # Jobs asynchrones (queue)
├── Events/                         # Événements
├── Listeners/                      # Écouteurs d'événements
├── Notifications/                  # Notifications
├── Observers/                      # Observers de modèles
└── Providers/                      # Service Providers

database/
├── migrations/                     # Migrations de schéma
├── seeders/                        # Seeders de données
└── factories/                      # Factories pour tests

config/
├── payment.php                     # Configuration des paiements
└── ...                             # Autres configs

routes/
├── api.php                         # Routes API REST
└── web.php                         # Routes web (si nécessaire)

tests/
├── Feature/                        # Tests d'intégration
└── Unit/                           # Tests unitaires

storage/
└── logs/                           # Logs applicatifs
```

---

## 🏗️ Principes Architecturaux

### 1. **Domain-Driven Design (DDD)**
- **Entities**: Objets avec identité (User, Card, Signature, Payment)
- **Value Objects**: Objets sans identité (Money)
- **Repositories**: Interface pour l'accès aux données
- **Services**: Logique métier complexe

### 2. **Séparation des préoccupations (SoC)**
- **Core**: Utilitaires transversaux
- **Domain**: Logique métier pure (indépendante de Laravel)
- **Application**: Orchestration de la logique métier
- **Infrastructure**: Détails techniques (BD, APIs externes)
- **Http**: Interface REST

### 3. **Modularité**
- Chaque module (Auth, User, Cards, etc.) est **complètement indépendant**
- Chaque module a sa propre structure DDD
- Facilite le scaling et la maintenance

---

## 🚀 Routes API

### Authentification (Publiques)
```
POST   /api/v1/auth/login              # Connexion
POST   /api/v1/auth/register           # Inscription
POST   /api/v1/auth/forgot-password    # Mot de passe oublié
POST   /api/v1/auth/reset-password     # Réinitialiser le mot de passe
POST   /api/v1/auth/refresh            # Rafraîchir le token
```

### Utilisateurs (Protégées)
```
GET    /api/v1/users                   # Lister les utilisateurs
POST   /api/v1/users                   # Créer un utilisateur
GET    /api/v1/users/{id}              # Récupérer un utilisateur
PUT    /api/v1/users/{id}              # Modifier un utilisateur
DELETE /api/v1/users/{id}              # Supprimer un utilisateur
```

### Cartes (Protégées)
```
GET    /api/v1/cards                   # Lister les cartes
POST   /api/v1/cards                   # Créer une carte
GET    /api/v1/cards/{id}              # Récupérer une carte
PUT    /api/v1/cards/{id}              # Modifier une carte
DELETE /api/v1/cards/{id}              # Supprimer une carte
POST   /api/v1/cards/{id}/publish      # Publier une carte
GET    /api/v1/cards/{id}/signatures   # Lister les signatures d'une carte
```

### Signatures (Protégées)
```
POST   /api/v1/signatures              # Créer une signature
GET    /api/v1/signatures/{id}         # Récupérer une signature
DELETE /api/v1/signatures/{id}         # Supprimer une signature
POST   /api/v1/signatures/validate     # Valider une signature
GET    /api/v1/cards/{id}/signatures   # Lister les signatures d'une carte
```

### Paiements (Protégées)
```
GET    /api/v1/payments                # Lister les paiements
POST   /api/v1/payments                # Créer un paiement
GET    /api/v1/payments/{id}           # Récupérer un paiement
POST   /api/v1/payments/{id}/process   # Traiter un paiement
POST   /api/v1/payments/{id}/refund    # Effectuer un remboursement
```

### Webhooks (Publiques - sécurisées)
```
POST   /api/v1/webhooks/payment        # Webhook de paiement
```

### Health Check (Publique)
```
GET    /api/v1/health                  # État de l'API
```

---

## 🔐 Authentification

L'API utilise **Laravel Sanctum** pour les tokens d'authentification.

- Tokens **API Token** pour les applications mobiles
- Support **CSRF** pour les requêtes web
- Middleware `auth:sanctum` pour protéger les routes

---

## 📝 Réponses API

Toutes les réponses suivent ce format standardisé :

### Succès
```json
{
  "success": true,
  "message": "Success",
  "data": { ... }
}
```

### Erreur
```json
{
  "success": false,
  "message": "Error message",
  "errors": { ... }
}
```

### Paginé
```json
{
  "success": true,
  "message": "Success",
  "data": [ ... ],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75
  }
}
```

---

## 💾 Configuration Base de Données

### Variables d'environnement
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eecsigncard
DB_USERNAME=root
DB_PASSWORD=
```

### Créer la base de données
```bash
php artisan migrate
php artisan db:seed
```

---

## 🧪 Tests

### Exécuter tous les tests
```bash
php artisan test
```

### Tests unitaires uniquement
```bash
php artisan test tests/Unit
```

### Tests d'intégration uniquement
```bash
php artisan test tests/Feature
```

---

## 🎯 Prochaines Étapes

1. **Implémenter les Services métier** dans chaque module
2. **Créer les Migrations** pour les tables (users, cards, signatures, payments, etc.)
3. **Implémenter les Repositories** pour l'accès aux données
4. **Créer les Modèles Eloquent** correspondants
5. **Ajouter les validations** dans les Form Requests
6. **Intégrer les passerelles de paiement** (Stripe, PayPal, etc.)
7. **Tests unitaires et d'intégration** complets
8. **Documentation API** avec OpenAPI/Swagger
9. **Déploiement** sur serveur production

---

## 📚 Ressources

- [Laravel Documentation](https://laravel.com/docs)
- [Domain-Driven Design](https://en.wikipedia.org/wiki/Domain-driven_design)
- [Clean Architecture](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [API REST Best Practices](https://restfulapi.net/)

---

**Version**: 1.0.0  
**Dernière mise à jour**: 2026-04-21  
**Mainteneur**: Votre Équipe  
