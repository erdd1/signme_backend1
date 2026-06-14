# Installation & Configuration - EEC SignCard API

## 📋 Prérequis

- PHP 8.2+
- MySQL 8.0+
- Composer 2.x
- Node.js 18+ (optionnel, pour Vite)
- Git

## 🚀 Installation

### 1. Cloner le projet

```bash
git clone <repository-url>
cd eecsigncard_api
```

### 2. Installer les dépendances

```bash
composer install
```

### 3. Copier le fichier .env

```bash
cp .env.example .env
```

### 4. Générer la clé d'application

```bash
php artisan key:generate
```

### 5. Configurer la base de données

Modifier le fichier `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eecsigncard
DB_USERNAME=root
DB_PASSWORD=votre_mot_de_passe
```

Créer la base de données:

```bash
mysql -u root -p -e "CREATE DATABASE eecsigncard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 6. Exécuter les migrations

```bash
php artisan migrate
```

### 7. Seeder la base de données (optionnel)

```bash
php artisan db:seed
```

### 8. Générer les clés pour Sanctum

```bash
php artisan install:api
```

### 9. Lancer le serveur de développement

```bash
php artisan serve
```

L'API est maintenant disponible à `http://localhost:8000`

---

## ⚙️ Configuration

### Variables d'environnement importantes

```env
# App
APP_NAME="EEC SignCard API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eecsigncard
DB_USERNAME=root
DB_PASSWORD=

# Authentication
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8100
JWT_EXPIRATION=3600

# Payment Gateway
PAYMENT_GATEWAY=stripe
STRIPE_KEY=sk_test_...
STRIPE_SECRET=sk_test_...

# Mail
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@eecsigncard.com"
```

### Fichiers de configuration

- `config/app.php` - Configuration générale
- `config/database.php` - Connexion BD
- `config/payment.php` - Passerelles de paiement
- `config/auth.php` - Authentification
- `config/cors.php` - CORS

---

## 🗄️ Base de Données

### Créer une migration

```bash
php artisan make:migration create_payments_table
```

### Exécuter les migrations

```bash
# Toutes les migrations
php artisan migrate

# Migrer avec seed
php artisan migrate --seed

# Faire un rollback
php artisan migrate:rollback

# Faire un rollback complet et recommencer
php artisan migrate:refresh --seed
```

### Créer un seeder

```bash
php artisan make:seeder PaymentSeeder
```

```php
namespace Database\Seeders;

use App\Models\Payment;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        Payment::factory()->count(50)->create();
    }
}
```

---

## 🧪 Tests

### Exécuter les tests

```bash
# Tous les tests
php artisan test

# Tests spécifiques
php artisan test tests/Feature/PaymentTest.php

# Avec couverture de code
php artisan test --coverage
```

### Créer un test

```bash
php artisan make:test Feature/PaymentTest
```

### Configuration des tests

Le fichier `phpunit.xml` contient la configuration.

---

## 📦 Dépendances principales

### Production

- **laravel/framework**: Framework Laravel
- **laravel/sanctum**: API Token Authentication
- **laravel/tinker**: REPL interactif
- **illuminate/cache**: Cache Laravel

### Développement

- **laravel/pint**: Formatage de code PHP
- **phpunit/phpunit**: Framework de test
- **laravel/sail**: Environnement Docker

### Installation de nouvelles dépendances

```bash
# Production
composer require package/name

# Développement
composer require --dev package/name

# Mettre à jour les dépendances
composer update

# Vérifier les dépendances obsolètes
composer outdated
```

---

## 🔧 Commandes utiles

### Développement

```bash
# Lancer le serveur de développement
php artisan serve

# Lancer la console interactive
php artisan tinker

# Efface le cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Générer la clé d'application
php artisan key:generate

# Générer la documentation des routes
php artisan route:list
```

### Nettoyage

```bash
# Nettoyer les fichiers de cache
php artisan optimize:clear

# Vider les fichiers de log
php artisan logs:clear

# Réinitialiser les migrations
php artisan migrate:reset
```

### Formatage et Linting

```bash
# Formater le code
./vendor/bin/pint

# Vérifier le code
./vendor/bin/pint --test
```

---

## 🐛 Debugging

### Utiliser Tinker

```bash
php artisan tinker

# Exemples
User::count()
User::all()
User::find(1)
$user = User::create(['name' => 'John', 'email' => 'john@example.com'])
```

### Logs

Les logs sont stockés dans `storage/logs/laravel.log`

```php
// Dans votre code
\Log::info('User created', ['user_id' => $user->id]);
\Log::error('Payment failed', ['error' => $exception->getMessage()]);
```

### Requêtes de débogage

Utiliser le middleware `debugbar`:

```bash
composer require barryvdh/laravel-debugbar --dev
```

---

## 🚀 Déploiement

### Avant le déploiement

```bash
# Vérifier que tout fonctionne
php artisan test

# Vérifier que les migrations peuvent s'exécuter
php artisan migrate --pretend
```

### Sur le serveur

```bash
# Cloner le projet
git clone <repository> /var/www/eecsigncard

cd /var/www/eecsigncard

# Installer les dépendances
composer install --no-dev --optimize-autoloader

# Copier .env
cp .env.example .env
# Modifier .env avec les valeurs de production

# Générer la clé
php artisan key:generate

# Exécuter les migrations
php artisan migrate --force

# Générer le cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Configurer les permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data /var/www/eecsigncard
```

### Configuration Nginx

```nginx
server {
    listen 80;
    server_name api.eecsigncard.com;
    root /var/www/eecsigncard/public;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 📞 Support

Pour des questions ou des problèmes:

1. Consulter la [documentation Laravel](https://laravel.com/docs)
2. Vérifier les logs: `storage/logs/laravel.log`
3. Contacter l'équipe de développement

---

**Version**: 1.0.0  
**Dernière mise à jour**: 2026-04-21
