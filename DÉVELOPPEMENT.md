# Guide de Développement - EEC SignCard API

## 🎯 Principes de base

Cette API suit une architecture **Domain-Driven Design (DDD)** pour assurer la scalabilité, la maintenabilité et la testabilité.

---

## 📦 Flux de Développement

### 1. Créer une nouvelle feature

Pour chaque nouvelle fonctionnalité, créez un **nouveau module** dans `app/Modules/`.

Exemple: Ajouter une fonctionnalité de **Notifications**

```
app/Modules/Notifications/
├── Domain/
│   └── NotificationEntity.php
├── Application/
│   └── NotificationService.php
├── Infrastructure/
│   └── NotificationRepository.php
└── Http/
    └── NotificationController.php
```

### 2. Flux de travail par couche

#### **Domain Layer** (Logique métier pure)

```php
// app/Modules/Notifications/Domain/NotificationEntity.php
namespace App\Modules\Notifications\Domain;

class NotificationEntity
{
    public function __construct(
        public $id,
        public $userId,
        public $message,
        public $type,
        public $createdAt = null,
    ) {}

    // Logique métier pure
    public function isUnread(): bool
    {
        return $this->readAt === null;
    }
}
```

**Règles:**
- Pas de dépendances Laravel (sauf si vraiment nécessaire)
- Logique métier exclusivement
- Tests unitaires rapides

---

#### **Application Layer** (Orchestration)

```php
// app/Modules/Notifications/Application/NotificationService.php
namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\NotificationRepository;

class NotificationService
{
    public function __construct(
        private NotificationRepository $repository
    ) {}

    public function create($userId, $message, $type)
    {
        $notification = new NotificationEntity(...);
        
        // Logique métier complexe
        event(new NotificationCreated($notification));
        
        return $this->repository->create($notification);
    }

    public function markAsRead($notificationId)
    {
        $notification = $this->repository->findById($notificationId);
        // Logique...
        return $this->repository->update($notificationId, [...]);
    }
}
```

**Règles:**
- Fait appel aux repositories pour l'accès données
- Émet des événements pour les actions importantes
- Dispatch des jobs pour les tâches asynchrones

---

#### **Infrastructure Layer** (Persistance)

```php
// app/Modules/Notifications/Infrastructure/NotificationRepository.php
namespace App\Modules\Notifications\Infrastructure;

use App\Models\Notification;

class NotificationRepository
{
    public function create($data)
    {
        return Notification::create($data);
    }

    public function findById($id)
    {
        return Notification::findOrFail($id);
    }

    public function update($id, $data)
    {
        $notification = $this->findById($id);
        $notification->update($data);
        return $notification;
    }
}
```

**Règles:**
- Utilise les modèles Eloquent (ORM)
- Implémente l'interface du Domain
- Gère les interactions avec la base de données

---

#### **Http Layer** (API REST)

```php
// app/Modules/Notifications/Http/NotificationController.php
namespace App\Modules\Notifications\Http;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Application\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function index()
    {
        $notifications = $this->service->getAll();
        return apiSuccess($notifications, 'Notifications retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'type' => 'required|in:info,warning,error,success',
        ]);

        $notification = $this->service->create(
            auth()->id(),
            $validated['message'],
            $validated['type']
        );

        return apiSuccess($notification, 'Notification created', 201);
    }

    public function markAsRead($id)
    {
        $notification = $this->service->markAsRead($id);
        return apiSuccess($notification, 'Notification marked as read');
    }
}
```

**Règles:**
- Reçoit les requêtes HTTP
- Valide les entrées
- Délègue la logique au Service
- Retourne des réponses JSON standardisées

---

## 🔄 Exemple complet: Implémenter "Créer une Signature"

### 1. Créer la Migration

```bash
php artisan make:migration create_signatures_table
```

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_signatures_table.php
Schema::create('signatures', function (Blueprint $table) {
    $table->id();
    $table->foreignId('card_id')->constrained();
    $table->foreignId('user_id')->constrained();
    $table->longText('signature_data'); // Image base64
    $table->ipAddress('ip_address')->nullable();
    $table->json('device_info')->nullable();
    $table->string('location')->nullable();
    $table->timestamp('signed_at')->nullable();
    $table->timestamps();
});
```

### 2. Créer le Modèle

```bash
php artisan make:model Signature
```

```php
// app/Models/Signature.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    protected $fillable = [
        'card_id', 'user_id', 'signature_data',
        'ip_address', 'device_info', 'location', 'signed_at'
    ];

    protected $casts = [
        'device_info' => 'array',
        'signed_at' => 'datetime',
    ];

    public function card()
    {
        return $this->belongsTo(Card::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### 3. Implémenter le Service

```php
// app/Modules/Signature/Application/SignatureService.php
public function create(array $data)
{
    // Valider les données métier
    if (!$this->isValidSignatureData($data['signature_data'])) {
        throw new InvalidSignatureException();
    }

    $signature = new SignatureEntity(
        id: null,
        cardId: $data['card_id'],
        userId: auth()->id(),
        signatureData: $data['signature_data'],
        ipAddress: request()->ip(),
        deviceInfo: $this->parseDeviceInfo(),
        location: $data['location'] ?? null,
        signedAt: now(),
    );

    $created = $this->repository->create($signature);

    // Émettre un événement
    event(new SignatureCreated($created));

    return $created;
}
```

### 4. Implémenter le Repository

```php
// app/Modules/Signature/Infrastructure/SignatureRepository.php
public function create($signature)
{
    return Signature::create([
        'card_id' => $signature->cardId,
        'user_id' => $signature->userId,
        'signature_data' => $signature->signatureData,
        'ip_address' => $signature->ipAddress,
        'device_info' => $signature->deviceInfo,
        'location' => $signature->location,
        'signed_at' => $signature->signedAt,
    ]);
}
```

### 5. Créer la Form Request

```bash
php artisan make:request StoreSignatureRequest
```

```php
// app/Http/Requests/StoreSignatureRequest.php
public function rules(): array
{
    return [
        'card_id' => 'required|exists:cards,id',
        'signature_data' => 'required|string|starts_with:data:image',
        'location' => 'nullable|string|max:255',
    ];
}
```

### 6. Créer le Controller

```php
// app/Modules/Signature/Http/SignatureController.php
public function store(StoreSignatureRequest $request)
{
    $signature = $this->service->create($request->validated());
    return apiSuccess($signature, 'Signature created', 201);
}
```

### 7. Ajouter les Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('signatures', SignatureController::class);
    Route::post('/signatures/validate', [SignatureController::class, 'validate']);
});
```

### 8. Écrire les Tests

```php
// tests/Feature/SignatureTest.php
public function test_user_can_create_signature()
{
    $user = User::factory()->create();
    $card = Card::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/signatures', [
        'card_id' => $card->id,
        'signature_data' => 'data:image/png;base64,...',
        'location' => 'Remote',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);
}
```

---

## 🛠️ Commandes utiles

```bash
# Créer un module complet
php artisan make:model Card -mcr

# Migrer la base de données
php artisan migrate
php artisan migrate --seed

# Faire un rollback
php artisan migrate:rollback

# Exécuter les tests
php artisan test

# Formater le code
./vendor/bin/pint

# Générer la documentation
php artisan l5-swagger:generate
```

---

## 🔐 Sécurité

### Authentification
- Utilise **Laravel Sanctum** pour les tokens API
- Middleware `auth:sanctum` sur les routes protégées
- Tokens API avec expiration configurable

### Autorisation
- Policies pour contrôler les actions par utilisateur
- Autorisation au niveau du Service

### Validation
- Form Requests pour valider les entrées
- Validation à la fois côté client (Flutter) et serveur

### CORS
- Configuration CORS dans `config/cors.php`
- Accepte les requêtes depuis l'app Flutter

---

## 📊 Logging & Monitoring

```php
// Utiliser les logs partout
\Log::info('User created', ['user_id' => $user->id]);
\Log::error('Payment failed', ['error' => $exception]);

// Événements importants
event(new SignatureCreated($signature));
```

---

## 🚀 Déploiement

### Pre-deployment
1. Exécuter tous les tests
2. Vérifier le code avec `php artisan tinker`
3. Exécuter les migrations

### Deployment
1. Copier le code sur le serveur
2. `composer install --no-dev`
3. `php artisan migrate --force`
4. `php artisan cache:clear`
5. `php artisan config:cache`

---

## 📚 Ressources

- [Laravel Best Practices](https://laravel-best-practices.com/)
- [DDD with Laravel](https://herbertlui.net/practical-ddd-with-laravel/)
- [API Design Patterns](https://cloud.google.com/apis/design/)

---

**Version**: 1.0.0  
**Dernière mise à jour**: 2026-04-21
