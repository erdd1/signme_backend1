# 📑 Index Documentation - EEC SignCard API

## 🎯 Démarrage Rapide

Commencez ici pour comprendre le projet rapidement:

1. [📖 README.md](README.md) - Vue d'ensemble générale
2. [🚀 SETUP.md](SETUP.md) - Installation et configuration
3. [🏗️ ARCHITECTURE.md](ARCHITECTURE.md) - Comprendre la structure DDD

---

## 📚 Documentation Complète

### Pour les Développeurs
- **[📖 DÉVELOPPEMENT.md](DÉVELOPPEMENT.md)** - Comment développer une nouvelle feature
  - Flux de travail par couche
  - Exemple complet: créer une signature
  - Commandes utiles
  - Tests

- **[✅ CONVENTIONS.md](CONVENTIONS.md)** - Standards de codage
  - Nommage (classes, méthodes, variables)
  - Structure des fichiers
  - PHPDoc
  - Checklist avant commit
  - Ce qu'il faut faire/éviter

- **[🏗️ ARCHITECTURE.md](ARCHITECTURE.md)** - Architecture technique
  - Vue d'ensemble DDD
  - Structure complète du projet
  - Principes architecturaux
  - Routes API complètes

- **[🚀 SETUP.md](SETUP.md)** - Configuration et déploiement
  - Installation locale
  - Configuration base de données
  - Commandes utiles
  - Déploiement production

### Pour les Administrateurs
- **[📝 CHANGELOG.md](CHANGELOG.md)** - Historique des changements
  - Nouvelle architecture DDD
  - Tous les fichiers créés
  - Caractéristiques entreprise
  - Prochaines étapes

### Pour les Chefs de Projet
- **[📊 PROJECT_STATUS.md](PROJECT_STATUS.md) (à créer)** - État du projet
  - Progression
  - Roadmap
  - Risques
  - Timeline

---

## 🗂️ Arborescence du Projet

```
eecsigncard_api/
├── app/
│   ├── Core/                    # Couche transversale
│   │   ├── Exceptions/
│   │   ├── Helpers/
│   │   ├── Traits/
│   │   └── Services/
│   ├── Domain/                  # Logique métier
│   │   ├── Entities/
│   │   ├── Interfaces/
│   │   └── ValueObjects/
│   ├── Application/             # Services applicatifs
│   │   ├── Services/
│   │   └── DTOs/
│   ├── Infrastructure/          # Persistance
│   │   ├── Repositories/
│   │   └── ExternalServices/
│   ├── Modules/                 # Modules métier
│   │   ├── Auth/
│   │   ├── User/
│   │   ├── Cards/
│   │   ├── Signature/
│   │   └── Payment/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Middleware/
│   ├── Policies/
│   ├── Jobs/
│   ├── Events/
│   ├── Listeners/
│   ├── Notifications/
│   ├── Observers/
│   └── Providers/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── routes/
│   ├── api.php                  # Routes API v1
│   └── web.php
├── config/
│   ├── payment.php              # Configuration paiements
│   └── ...
├── storage/
│   └── logs/                    # Logs applicatifs
├── tests/
│   ├── Feature/
│   ├── Unit/
│   └── TestCase.php
├── .env.example                 # Variables d'environnement
├── README.md                    # Readme principal
├── ARCHITECTURE.md              # Architecture DDD
├── DÉVELOPPEMENT.md             # Guide développement
├── CONVENTIONS.md               # Conventions de codage
├── SETUP.md                     # Installation & config
├── CHANGELOG.md                 # Changements
└── composer.json                # Dépendances PHP
```

---

## 🔑 Concepts Clés

### Domain-Driven Design (DDD)

Le projet suit l'architecture **DDD** organisée en couches:

1. **Core Layer** - Services transversaux (Helpers, Traits, Exceptions)
2. **Domain Layer** - Logique métier pure (Entities, Interfaces, ValueObjects)
3. **Application Layer** - Orchestration (Services, DTOs)
4. **Infrastructure Layer** - Persistance (Repositories, Connexions BD)
5. **HTTP Layer** - Interface REST (Controllers, Requests, Middleware)

### Modules
- Chaque fonctionnalité majeure est un **module autonome**
- Chaque module a sa propre structure DDD
- Favorise la scalabilité et la maintenabilité

### Réponses API Standardisées
```json
{
  "success": true/false,
  "message": "Description",
  "data": {...},
  "pagination": {...}  // si applicable
}
```

---

## 🎯 Modules du Projet

### 1. Auth Module
**Localisation**: `app/Modules/Auth/`

Gère:
- Connexion/inscription
- Authentification avec Sanctum
- Refresh tokens
- Validation credentials

Endpoints:
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

### 2. User Module
**Localisation**: `app/Modules/User/`

Gère:
- Gestion des utilisateurs
- Profils
- Rôles et permissions

Endpoints:
- `CRUD /api/v1/users`

### 3. Cards Module
**Localisation**: `app/Modules/Cards/`

Gère:
- Cartes de sainte cène
- États (draft, published, archived)
- Métadonnées

Endpoints:
- `CRUD /api/v1/cards`
- `POST /api/v1/cards/{id}/publish`

### 4. Signature Module
**Localisation**: `app/Modules/Signature/`

Gère:
- Signatures numériques
- Validation signatures
- Metadata (IP, device, location)

Endpoints:
- `CRUD /api/v1/signatures`
- `POST /api/v1/signatures/validate`
- `GET /api/v1/cards/{id}/signatures`

### 5. Payment Module
**Localisation**: `app/Modules/Payment/`

Gère:
- Paiements
- Passerelles (Stripe, PayPal, Orange Money, MTN)
- Remboursements
- Webhooks

Endpoints:
- `CRUD /api/v1/payments`
- `POST /api/v1/payments/{id}/process`
- `POST /api/v1/payments/{id}/refund`
- `POST /api/v1/webhooks/payment`

---

## 🔗 Routes API Principales

### Authentication (Publiques)
```
POST   /api/v1/auth/login
POST   /api/v1/auth/register
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
POST   /api/v1/auth/refresh
```

### Protégées (Authentification requise)
```
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}

GET    /api/v1/cards
POST   /api/v1/cards
GET    /api/v1/cards/{id}
PUT    /api/v1/cards/{id}
DELETE /api/v1/cards/{id}
POST   /api/v1/cards/{id}/publish
GET    /api/v1/cards/{id}/signatures

POST   /api/v1/signatures
GET    /api/v1/signatures/{id}
DELETE /api/v1/signatures/{id}
POST   /api/v1/signatures/validate

GET    /api/v1/payments
POST   /api/v1/payments
GET    /api/v1/payments/{id}
POST   /api/v1/payments/{id}/process
POST   /api/v1/payments/{id}/refund
```

### Webhooks (Sécurisés)
```
POST   /api/v1/webhooks/payment
```

### Health
```
GET    /api/v1/health
```

---

## 🛠️ Commandes Utiles

```bash
# Installation
composer install
php artisan migrate --seed

# Développement
php artisan serve                   # Lancer le serveur
php artisan tinker                  # Console interactive
php artisan test                    # Exécuter les tests
./vendor/bin/pint                   # Formater le code

# Database
php artisan make:migration nom      # Créer une migration
php artisan make:model Model        # Créer un modèle
php artisan make:seeder Seeder      # Créer un seeder

# Cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

---

## 📊 Architecture en Diagramme

```
┌───────────────────────────────────────────┐
│     CLIENT (Flutter App)                  │
└──────────────────┬──────────────────────┬─┘
                   │                      │
            HTTP Requests          WebSocket
                   │                      │
┌──────────────────▼──────────────────────┼─┐
│          HTTP Layer (Controllers)        │ │
│  ┌──────────────────────────────────┐   │ │
│  │  PaymentController, UserController   │ │
│  └──────────────────────────────────┘   │ │
└──────────────────┬──────────────────────┼─┘
                   │                      │
┌──────────────────▼──────────────────────┼─┐
│      Application Layer (Services)        │ │
│  ┌──────────────────────────────────┐   │ │
│  │  PaymentService, UserService     │   │ │
│  └──────────────────────────────────┘   │ │
└──────────────────┬──────────────────────┼─┘
                   │                      │
┌──────────────────▼──────────────────────┼─┐
│        Domain Layer (Entities)           │ │
│  ┌──────────────────────────────────┐   │ │
│  │ PaymentEntity, UserEntity        │   │ │
│  └──────────────────────────────────┘   │ │
└──────────────────┬──────────────────────┼─┘
                   │                      │
┌──────────────────▼──────────────────────┼─┐
│   Infrastructure Layer (Repositories)    │ │
│  ┌──────────────────────────────────┐   │ │
│  │ PaymentRepository, UserRepository│   │ │
│  └──────────────────────────────────┘   │ │
└──────────────────┬──────────────────────┼─┘
                   │                      │
┌──────────────────▼──────────────────────┼─┐
│         MySQL Database                   │ │
│  ┌──────────────────────────────────┐   │ │
│  │ payments, users, cards, signatures   │ │
│  └──────────────────────────────────┘   │ │
└──────────────────────────────────────────┘
```

---

## 📌 Checklist Avant Commencer

- [ ] Lire [README.md](README.md)
- [ ] Suivre [SETUP.md](SETUP.md) pour l'installation
- [ ] Comprendre [ARCHITECTURE.md](ARCHITECTURE.md)
- [ ] Lire [DÉVELOPPEMENT.md](DÉVELOPPEMENT.md) avant de coder
- [ ] Respecter [CONVENTIONS.md](CONVENTIONS.md)
- [ ] Vérifier [CHANGELOG.md](CHANGELOG.md) pour le contexte

---

## 🔗 Ressources Externes

- [Laravel Documentation](https://laravel.com/docs)
- [Domain-Driven Design](https://en.wikipedia.org/wiki/Domain-driven_design)
- [RESTful API Design](https://restfulapi.net/)
- [PHP PSR Standards](https://www.php-fig.org/)

---

**Dernière mise à jour**: 2026-04-21  
**Version**: 1.0.0
