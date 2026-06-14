# EEC SignCard API

> 🎯 **API REST Mobile Production-Ready** pour l'application de signature de cartes de sainte cène

[![Laravel](https://img.shields.io/badge/Laravel-11+-ff2d20?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777bb4?style=flat-square&logo=php)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-00758f?style=flat-square&logo=mysql)](https://mysql.com)
[![DDD](https://img.shields.io/badge/Architecture-DDD-success?style=flat-square)]()

---

## 📋 À propos

**EEC SignCard API** est une application REST professionnelle construite avec Laravel utilisant l'architecture **Domain-Driven Design (DDD)**. Elle permet de gérer les signatures de cartes de sainte cène à distance et en présentiel avec :

✅ **Scalabilité** - Architecture modulaire et séparation des préoccupations  
✅ **Performance** - Optimisée pour les requêtes mobiles  
✅ **Sécurité** - Authentification, autorisation, validation stricte  
✅ **Maintenabilité** - Code propre, testé et documenté  
✅ **Ergonomie** - API REST cohérente et intuitive  

---

## 🚀 Quick Start

### Prérequis
- PHP 8.2+
- MySQL 8.0+
- Composer 2.x

### Installation

```bash
# Cloner le projet
git clone <repository-url> && cd eecsigncard_api

# Installer les dépendances
composer install

# Configurer l'environnement
cp .env.example .env
php artisan key:generate

# Configurer la base de données dans .env, puis :
php artisan migrate --seed

# Lancer le serveur
php artisan serve
```

L'API est disponible à `http://localhost:8000`

📖 [Guide d'installation complet](SETUP.md)

---

## 🏗️ Architecture

L'application suit une architecture **Domain-Driven Design (DDD)** avec ces couches :

```
┌─────────────────────────────────────┐
│      HTTP Layer (Controllers)       │  ← Requêtes API
├─────────────────────────────────────┤
│    Application Layer (Services)     │  ← Orchestration
├─────────────────────────────────────┤
│      Domain Layer (Entities)        │  ← Logique métier pure
├─────────────────────────────────────┤
│  Infrastructure Layer (Repositories)│  ← Persistance
├─────────────────────────────────────┤
│      Core Layer (Utilities)         │  ← Transversal
└─────────────────────────────────────┘
```

### Modules

- **Auth** - Authentification & autorisation
- **User** - Gestion des utilisateurs
- **Cards** - Gestion des cartes
- **Signature** - Gestion des signatures
- **Payment** - Gestion des paiements

📖 [Architecture détaillée](ARCHITECTURE.md)

---

## 📡 Routes API

### Authentification
```
POST   /api/v1/auth/login              Connexion
POST   /api/v1/auth/register           Inscription
POST   /api/v1/auth/logout             Déconnexion
POST   /api/v1/auth/refresh            Rafraîchir le token
```

### Ressources (Protégées)
```
GET    /api/v1/users                   Lister les utilisateurs
POST   /api/v1/cards                   Créer une carte
GET    /api/v1/cards/{id}/signatures   Lister les signatures
POST   /api/v1/signatures              Créer une signature
GET    /api/v1/payments                Lister les paiements
POST   /api/v1/payments                Créer un paiement
```

📖 [Liste complète des routes](ARCHITECTURE.md#-routes-api)

---

## 🧪 Tests

```bash
# Exécuter tous les tests
php artisan test

# Tests avec couverture
php artisan test --coverage

# Tests spécifiques
php artisan test tests/Feature/PaymentTest.php
```

---

## 📚 Documentation

- [🏗️ Architecture du projet](ARCHITECTURE.md)
- [📖 Guide de développement](DÉVELOPPEMENT.md)
- [✅ Conventions de codage](CONVENTIONS.md)
- [🚀 Installation & configuration](SETUP.md)

---

## 🔐 Sécurité

- ✅ Authentification avec **Laravel Sanctum**
- ✅ Autorisation avec **Policies**
- ✅ Validation stricte des inputs
- ✅ Rate limiting sur les endpoints sensibles
- ✅ CORS configuré pour Flutter
- ✅ Logs d'audit de toutes les actions importantes

---

## 🛠️ Stack Technologique

- **Framework**: Laravel 11+
- **Base de données**: MySQL 8.0+
- **Authentification**: Laravel Sanctum
- **Queue**: Redis/Sync
- **Cache**: File/Redis
- **Testing**: PHPUnit
- **ORM**: Eloquent

---

## 📦 Commandes utiles

```bash
# Développement
php artisan serve                      # Lancer le serveur
php artisan tinker                     # Console interactive

# Base de données
php artisan migrate                    # Exécuter les migrations
php artisan migrate:rollback           # Annuler les migrations
php artisan db:seed                    # Charger les données

# Cache & Config
php artisan cache:clear                # Vider le cache
php artisan config:cache               # Cacher la config

# Code
./vendor/bin/pint                      # Formater le code
php artisan test                       # Exécuter les tests
```

📖 [Commandes complètes](SETUP.md#-commandes-utiles)

---

## 🚀 Déploiement

### Production

```bash
# Installer les dépendances sans dev
composer install --no-dev --optimize-autoloader

# Générer le cache
php artisan config:cache
php artisan route:cache

# Exécuter les migrations
php artisan migrate --force
```

### Serveur Nginx

```nginx
server {
    listen 80;
    server_name api.eecsigncard.com;
    root /var/www/eecsigncard/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi_params;
    }
}
```

📖 [Guide complet de déploiement](SETUP.md#-déploiement)

---

## 📞 Support & Contribution

Pour des questions ou des problèmes :

1. Consulter la [documentation complète](ARCHITECTURE.md)
2. Vérifier les [logs](storage/logs)
3. Contacter l'équipe de développement

---

## 📝 Licence

Cette application est propriétaire. Tous droits réservés.

---

## 👥 Équipe

- **Architecture & Lead**: [Votre nom]
- **Stack**: Laravel + Flutter + MySQL

---

**Version**: 1.0.0  
**Statut**: 🟢 Production Ready  
**Dernière mise à jour**: 2026-04-21  
**Prochaine release**: 1.1.0 (Q2 2026)  

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
