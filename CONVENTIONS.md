# Conventions de Codage - EEC SignCard API

## 📝 Nommage

### Classes
- **PascalCase**: `UserController`, `PaymentService`, `SignatureEntity`
- Les interfaces se terminent par `Interface`: `PaymentRepositoryInterface`
- Les traits commencent par `Trait`: `ApiResponseTrait` ❌ Utiliser le suffix `-able`: `ApiResponseable`

### Méthodes
- **camelCase**: `getUserById()`, `processPayment()`, `validateSignature()`
- Commencer par un verbe: `get`, `create`, `update`, `delete`, `process`
- Tests: `test_user_can_create_signature()`, `test_payment_fails_with_invalid_card()`

### Variables
- **camelCase**: `$userId`, `$cardData`, `$isValid`
- Éviter les abréviations cryptiques
- Booléennes commencent par `is`, `has`, `should`: `$isActive`, `$hasSignature`

### Constantes
- **UPPER_SNAKE_CASE**: `MAX_SIGNATURE_SIZE`, `PAYMENT_TIMEOUT`

### Fichiers
- Classes: **PascalCase.php**: `UserController.php`, `PaymentService.php`
- Config: **snake_case.php**: `payment.php`, `signature.php`
- Migrations: **timestamp_slug_name.php**: `2024_01_15_123456_create_signatures_table.php`

---

## 🏗️ Structure des Fichiers

### Services (Application Layer)

```php
namespace App\Modules\Payment\Application;

class PaymentService
{
    public function __construct(
        private PaymentRepository $repository,
        private PaymentGatewayService $gateway,
    ) {}

    /**
     * Traiter un paiement
     * 
     * @param array $data
     * @return Payment
     * @throws PaymentException
     */
    public function process(array $data): Payment
    {
        // Validation
        // Logique métier
        // Persistance
        // Événements
    }
}
```

**Règles:**
- Une seule responsabilité
- Utiliser l'injection de dépendance
- Ajouter des PHPDoc pour chaque méthode publique
- Lancer des exceptions plutôt que retourner null

### Repositories (Infrastructure Layer)

```php
namespace App\Modules\Payment\Infrastructure;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function findById($id): ?Payment
    {
        return Payment::find($id);
    }

    public function getByUser($userId, $paginate = true)
    {
        $query = Payment::where('user_id', $userId);
        return $paginate ? $query->paginate() : $query->get();
    }
}
```

**Règles:**
- Utiliser les modèles Eloquent directement
- Implémenter les interfaces du Domain
- Pas de logique métier, juste de l'accès aux données

### Controllers

```php
namespace App\Modules\Payment\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;

class PaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private PaymentService $service
    ) {}

    public function store(StorePaymentRequest $request)
    {
        try {
            $payment = $this->service->create($request->validated());
            return $this->successResponse($payment, 'Payment created', 201);
        } catch (PaymentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
```

**Règles:**
- Hériter de `Controller`
- Utiliser les Traits réutilisables (`ApiResponseTrait`)
- Injecter les services
- Valider avec les Form Requests
- Gérer les exceptions
- Retourner des réponses standardisées

### Form Requests

```php
namespace App\Http\Requests;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'card_id' => 'required|exists:cards,id',
            'amount' => 'required|numeric|min:100|max:10000000',
            'currency' => 'required|in:XOF,EUR,USD',
            'payment_method' => 'required|in:card,bank,mobile_money',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The payment amount is required',
            'amount.numeric' => 'The amount must be a number',
            'amount.min' => 'The minimum amount is 100',
        ];
    }

    public function prepareForValidation()
    {
        $this->merge([
            'user_id' => auth()->id(),
        ]);
    }
}
```

**Règles:**
- Toujours implémenter `authorize()`
- Ajouter des messages d'erreur personnalisés
- Utiliser `prepareForValidation()` pour normaliser les données

---

## 🧪 Tests

### Structure

```
tests/
├── Feature/
│   ├── Auth/
│   │   └── LoginTest.php
│   ├── Payment/
│   │   └── PaymentTest.php
│   └── Signature/
│       └── SignatureTest.php
└── Unit/
    ├── Services/
    │   └── PaymentServiceTest.php
    └── Domain/
        └── MoneyTest.php
```

### Exemple de Test Feature

```php
namespace Tests\Feature\Payment;

use Tests\TestCase;
use App\Models\User;
use App\Models\Card;

class PaymentTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_create_payment()
    {
        $card = Card::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payments', [
                'card_id' => $card->id,
                'amount' => 5000,
                'currency' => 'XOF',
                'payment_method' => 'card',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_payment_fails_without_authentication()
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/v1/payments', [
            'card_id' => $card->id,
            'amount' => 5000,
        ]);

        $response->assertStatus(401);
    }

    public function test_payment_fails_with_invalid_amount()
    {
        $card = Card::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payments', [
                'card_id' => $card->id,
                'amount' => 50, // Trop bas
                'currency' => 'XOF',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
```

**Règles:**
- Un test par scénario
- Noms de test clairs et descriptifs: `test_` + sujet + résultat
- Utiliser les Factories pour les données de test
- Tester les cas d'erreur
- Vérifier la structure des réponses JSON

---

## 📚 Documentation

### PHPDoc

```php
/**
 * Traiter un paiement
 * 
 * @param  PaymentDTO $dto       Data Transfer Object avec les infos de paiement
 * @param  bool $async           Si vrai, le traitement est asynchrone
 * @return Payment               L'objet paiement créé
 * @throws PaymentException      Si le paiement échoue
 * @throws ValidationException   Si les données sont invalides
 */
public function process(PaymentDTO $dto, bool $async = false): Payment
{
    // ...
}
```

### Routes

```php
/**
 * @OA\Post(
 *     path="/api/v1/payments",
 *     summary="Créer un paiement",
 *     tags={"Payments"},
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"card_id","amount"},
 *             @OA\Property(property="card_id", type="integer"),
 *             @OA\Property(property="amount", type="number"),
 *         )
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Paiement créé"
 *     )
 * )
 */
Route::post('/payments', [PaymentController::class, 'store']);
```

---

## ✅ Checklist avant commit

- [ ] Tests passent: `php artisan test`
- [ ] Code formaté: `./vendor/bin/pint`
- [ ] Pas d'erreurs: `php artisan tinker`
- [ ] PHPDoc sur les méthodes publiques
- [ ] Migrations créées si nécessaire
- [ ] Logs ajoutés pour les actions importantes
- [ ] Exception handling en place
- [ ] Routes enregistrées dans `routes/api.php`

---

## 🚫 À Éviter

❌ Logique métier dans les Controllers
❌ Requêtes SQL directes (utiliser Eloquent)
❌ Variables globales
❌ Fonctions super longues (> 30 lignes)
❌ Pas de tests
❌ Hardcoder les valeurs (utiliser `config()`)
❌ Catch les exceptions génériques
❌ Laisser les `dd()` ou `var_dump()` en production

---

## ✅ À Faire

✅ Une responsabilité par classe
✅ Injection de dépendance
✅ Tests écrits en même temps que le code
✅ Revoir le code avec les collègues
✅ Documenter les changements importants
✅ Logs détaillés pour le debugging
✅ Gestion d'erreurs explicite
✅ Utiliser les DTOs pour les transferts de données

---

**Version**: 1.0.0  
**Dernière mise à jour**: 2026-04-21
