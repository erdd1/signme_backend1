# 🔍 ANALYSE COMPLÈTE - Projet Flutter EEC SignCard

**Date** : 22 Avril 2026  
**Projet** : `eecsigncardbon` (C:\Users\NOUETAGNIYVAN\AndroidStudioProjects\eecsigncardbon)  
**Architecture** : Clean Architecture + Riverpod + GoRouter  
**Environnement** : Flutter 3.3.0+ | Dart 3.0+

---

## 📋 TABLE DES MATIÈRES

1. [Structure Complète lib/](#structure-complète)
2. [État d'Implémentation](#état-dimplément)
3. [Fichiers Manquants](#fichiers-manquants)
4. [Patterns & Best Practices](#patterns--best-practices)
5. [Configuration & Setup](#configuration--setup)
6. [Prochaines Étapes](#prochaines-étapes)

---

## 📂 STRUCTURE COMPLÈTE

### Arborescence Détaillée

```
lib/
├── main.dart [91 lignes]
│   └── Point d'entrée app
│       - ProviderScope (Riverpod)
│       - MaterialApp.router (GoRouter)
│       - SystemChrome settings (orientation portrait + status bar)
│       - Localization FR (CM/FR/EN)
│       - Theme light (TODO: lire desde preferences)
│
├── app/ [Configuration globale]
│   ├── di/                    [Dossier potentiel pour DI]
│   ├── pages/ [Pages du shell - 5 fichiers]
│   │   ├── home_page.dart         [Dashboard principal]
│   │   ├── ma_carte_page.dart     [Affiche carte + historique]
│   │   ├── signature_page.dart    [Point d'entrée signatures]
│   │   ├── publications_page.dart [Flux de publications]
│   │   └── profil_page.dart       [Profil utilisateur]
│   ├── router/
│   │   └── app_router.dart [150+ lignes]
│   │       - GoRouter principal ✅
│   │       - Routes définies (5 main + auth)
│   │       - ShellRoute avec Bottom Navigation
│   │       - Redirect logic (authProvider watch)
│   │       - Transitions personnalisées (fade, slide)
│   ├── routes/
│   │   └── app_router.dart ⚠️ DOUBLON - À SUPPRIMER
│   │       - Ancien système de routes (non utilisé)
│   └── shell/
│       └── main_shell.dart [Widget shell + bottom nav]
│
├── core/ [Infrastructure & configuration]
│   ├── animations/            [Dossier potentiel pour animations]
│   ├── config/
│   │   └── app_config.dart [30 lignes]
│   │       - Env switch (dev/staging/prod)
│   │       - apiBaseUrl: 10.0.2.2:8000/api/v1 (dev)
│   │       - Feature flags (darkMode, offline, crashlytics, logs)
│   ├── constants/
│   │   └── app_constants.dart [100+ lignes]
│   │       - Storage keys (token, user, theme, onboarded)
│   │       - Pagination (defaultPerPage: 20, publications: 10)
│   │       - Timeouts (30s connect, 30s receive)
│   │       - Rôles (admin, pasteur, secretaire, tresorier, ancien, paroissien)
│   │       - Payment methods (Orange Money, MTN, Espèces)
│   │       - Montants distance [500-10000]
│   │       - Mois noms (fr + abr)
│   │       - Animation durations (fast 200ms, medium 350ms, slow 500ms)
│   ├── errors/                 [Dossier ERROR HANDLING - à compléter]
│   ├── localization/           [i18n - potentiel pour expansions]
│   ├── network/
│   │   └── api_client.dart [90 lignes]
│   │       - Singleton Dio instance
│   │       - Base options (timeouts, headers, contentType)
│   │       - _AuthInterceptor:
│   │         • Injecte "Authorization: Bearer <token>"
│   │         • Gère 401 → StorageService.clearAll()
│   │       - _LogInterceptor (debug mode uniquement):
│   │         • Log requests avec émojis 🌐 ✅ ❌
│   │       - Méthodes: get, post, put, patch, delete, postFormData
│   ├── services/
│   │   ├── storage_service.dart    [SharedPreferences + keys manager]
│   │   └── secure_storage_service.dart [Flutter Secure Storage]
│   ├── theme/
│   │   └── app_theme.dart [150+ lignes]
│   │       - PRIMARY: #4A1C7C (Violet EEC)
│   │       - SECONDARY: #D4AF37 (Or liturgique)
│   │       - ACCENT: #9C27B0
│   │       - Functional colors (success, error, warning, info)
│   │       - Neutral palette (background, surface, text)
│   │       - Radius scale: xs(6) → xxl(40)
│   │       - Spacing scale: xxs(2) → 5xl(48)
│   │       - Gradients: primaryGradient, cardGradient, goldGradient
│   │       - Shadows: shadowS, shadowM, shadowL
│   │       - Font: Poppins (Regular, Medium, SemiBold, Bold, Light)
│   ├── utils/                  [Dossier pour utilitaires]
│   └── widgets/ [4 widgets partagés]
│       ├── app_button.dart [100+ lignes]
│       │   - AppButton (filled) + AppButton.outlined
│       │   - isLoading state avec spinner
│       │   - Gradient + shadow
│       │   - Disabled state
│       ├── app_text_field.dart [Champ de texte personnalisé]
│       ├── app_shimmer.dart [Skeleton loading]
│       └── app_snackbar.dart [Notifications]
│
├── features/ [Clean Architecture - Domaines métier]
│
│   ├── auth/ [✅ COMPLETE - 3 pages, 4 models, 5 providers]
│   │   ├── data/
│   │   │   ├── datasources/
│   │   │   │   └── auth_remote_datasource.dart [Interface + implémentation]
│   │   │   │       - login(email, pwd) → AuthModel
│   │   │   │       - logout()
│   │   │   │       - forgotPassword(email)
│   │   │   │       - changePassword(current, newPwd)
│   │   │   │       - getMe() → UserModel
│   │   │   ├── models/
│   │   │   │   └── auth_model.dart [40 lignes]
│   │   │   │       class AuthModel(user, token)
│   │   │   │       class UserModel(extends UserEntity)
│   │   │   │         - 19 propriétés (id, nom, email, role, etc.)
│   │   │   │         - factory fromJson
│   │   │   │         - toJson()
│   │   │   └── repositories/
│   │   │       └── auth_repository.dart [Interface + implémentation]
│   │   │           - login(email, pwd) → Either<Failure, UserEntity>
│   │   │           - logout()
│   │   │           - isAuthenticated()
│   │   │           - getCachedUser()
│   │   │           - forgotPassword(email)
│   │   │           - changePassword(current, newPwd)
│   │   │           - getMe()
│   │   ├── domain/
│   │   │   ├── entities/
│   │   │   │   └── user_entity.dart [60 lignes]
│   │   │   │       class UserEntity (Equatable):
│   │   │   │         - int id
│   │   │   │         - String nom, email, role
│   │   │   │         - bool actif, estDeService
│   │   │   │         - String? church, phone, quartier, profession, groupe
│   │   │   │         - String? dateConfirmation, lieuConfirmation
│   │   │   │         - ChurchBrief? church
│   │   │   │       Getters:
│   │   │   │         - isAdmin, isPasteur, isSecretaire, isTresorier, isAncien
│   │   │   │         - canSignPresentiel, canManageMembers, canAccessReports
│   │   │   │         - roleLabel, initials
│   │   │   ├── repositories/ [Interface abstraite]
│   │   │   └── usecases/ [À déterminer]
│   │   ├── di/
│   │   │   └── auth_providers.di.dart [À créer ou nommer]
│   │   └── presentation/
│   │       ├── pages/
│   │       │   ├── splash_page.dart [80 lignes] ✅
│   │       │   │   - Affiche logo EEC + tagline
│   │       │   │   - Animation elastic scale + fade
│   │       │   │   - Spinner circular
│   │       │   │   - Redirection automatique (via GoRouter)
│   │       │   ├── login_page.dart ✅
│   │       │   │   - Email + Password fields
│   │       │   │   - Forgot Password link
│   │       │   │   - Sign In button
│   │       │   │   - Error display
│   │       │   └── forgot_password_page.dart ✅
│   │       │       - Email input
│   │       │       - Reset button
│   │       ├── providers/
│   │       │   └── auth_provider.dart [100+ lignes]
│   │       │       final authProvider = StateNotifierProvider<AuthNotifier, AuthState>
│   │       │       final apiClientProvider = Provider<ApiClient>
│   │       │       final storageServiceProvider = Provider<StorageService>
│   │       │       final authRemoteDataSourceProvider
│   │       │       final authRepositoryProvider
│   │       ├── state/
│   │       │   └── auth_state.dart [35 lignes]
│   │       │       6 états:
│   │       │         - AuthInitial
│   │       │         - AuthLoading
│   │       │         - AuthAuthenticated(UserEntity)
│   │       │         - AuthUnauthenticated
│   │       │         - AuthError(message, errors?)
│   │       │         - AuthSuccess(message)
│   │       └── widgets/ [Potentiel pour widgets réutilisables]
│   │
│   ├── cards/ [⚠️ PARTIAL - 1 page, 4 models, 3 providers]
│   │   ├── data/
│   │   │   ├── datasources/
│   │   │   │   └── card_remote_datasource.dart
│   │   │   │       - getMyCard() → CardModel
│   │   │   │       - getSignatureHistory() → List<SignatureHistoryModel>
│   │   │   │       - getCardWithHistory()
│   │   │   ├── models/
│   │   │   │   └── card_model.dart [80 lignes]
│   │   │   │       class CardModel(extends CardEntity):
│   │   │   │         - id, userId, currentMonth, totalSignatures, totalAmount, status
│   │   │   │         - createdAt, updatedAt
│   │   │   │       class SignatureHistoryModel:
│   │   │   │         - id, cardId, month, count, amount
│   │   │   │         - paymentMethod, signatureType, createdAt
│   │   │   └── repositories/
│   │   │       └── card_repository_impl.dart
│   │   ├── domain/
│   │   │   ├── entities/
│   │   │   │   └── card_entity.dart [40 lignes]
│   │   │   │       class CardEntity(Equatable):
│   │   │   │         - int id, userId
│   │   │   │         - String currentMonth, status
│   │   │   │         - int totalSignatures
│   │   │   │         - double totalAmount
│   │   │   │         - DateTime createdAt, updatedAt
│   │   │   │       class SignatureHistoryEntity
│   │   │   ├── repositories/ [Interface]
│   │   │   └── usecases/
│   │   ├── di/
│   │   └── presentation/
│   │       ├── pages/
│   │       │   └── ma_carte_page.dart [120+ lignes] ✅
│   │       │       - FutureProvider cardWithHistoryProvider
│   │       │       - Affiche carte (solde, mois, signatures)
│   │       │       - Affiche historique (tableau)
│   │       │       - Shimmer loading
│   │       │       - Error state avec retry button
│   │       ├── providers/
│   │       │   └── card_providers.dart [60 lignes]
│   │       │       final cardRemoteDatasourceProvider
│   │       │       final cardRepositoryProvider
│   │       │       final myCardProvider = FutureProvider<CardEntity>
│   │       │       final signatureHistoryProvider = FutureProvider<List<SignatureHistoryEntity>>
│   │       │       final cardWithHistoryProvider = FutureProvider<(CardEntity, List<History>)>
│   │       ├── state/ [Vide - utilise Riverpod directement]
│   │       └── widgets/
│   │
│   ├── signature/ [❌ INCOMPLETE - 0 pages, 2 models, 6 providers]
│   │   ├── data/
│   │   │   ├── datasources/
│   │   │   │   └── signature_remote_datasource.dart
│   │   │   │       - createDistanceSignature(payload) → SignatureModel
│   │   │   │       - createPresentielSignature(payload) → SignatureModel
│   │   │   │       - getHistory() → List<SignatureModel>
│   │   │   │       - getSignatureDetails(id) → SignatureModel
│   │   │   │       - confirmPayment(id, reference) → SignatureModel
│   │   │   ├── models/
│   │   │   │   └── signature_model.dart [70 lignes]
│   │   │   │       class SignatureModel(extends SignatureEntity):
│   │   │   │         - id, userId, churchId
│   │   │   │         - amount, fees
│   │   │   │         - paymentMethod (Orange Money, MTN, Cash)
│   │   │   │         - signatureType (distance, presentiel)
│   │   │   │         - status (pending, confirmed, failed)
│   │   │   │         - confirmingUserId, paymentReference, notes
│   │   │   │         - createdAt, confirmedAt
│   │   │   └── repositories/
│   │   │       └── signature_repository_impl.dart
│   │   ├── domain/
│   │   │   ├── entities/
│   │   │   │   └── signature_entity.dart [50 lignes]
│   │   │   │       class SignatureEntity
│   │   │   │       class SignatureDistancePayload(amount, paymentMethod)
│   │   │   │       class SignaturePresentielPayload(amount, paymentMethod, notes)
│   │   │   ├── repositories/ [Interface]
│   │   │   └── usecases/
│   │   ├── di/
│   │   └── presentation/
│   │       ├── pages/ [❌ VIDE]
│   │       │   ├── sign_distance_page.dart ❌ À CRÉER
│   │       │   │   Écran pour signature à distance
│   │       │   │   - Liste de montants autorisés (AppConstants.montantsDistance)
│   │       │   │   - Choix méthode paiement (OrangeMoney, MTN, Espèces)
│   │       │   │   - Formulaire (montant, méthode, notes)
│   │       │   │   - Bouton valider (appelle createDistanceSignatureProvider)
│   │       │   └── sign_presentiel_page.dart ❌ À CRÉER
│   │       │       Écran pour signature présentiel (pasteur/ancien)
│   │       │       - Liste membres à signer
│   │       │       - Montant + méthode paiement
│   │       │       - Confirmation caissier
│   │       ├── providers/
│   │       │   └── signature_providers.dart [80 lignes]
│   │       │       final signatureRemoteDatasourceProvider
│   │       │       final signatureRepositoryProvider
│   │       │       final createDistanceSignatureProvider = FutureProvider.family<Signature, SignatureDistancePayload>
│   │       │       final createPresentielSignatureProvider = FutureProvider.family<Signature, SignaturePresentielPayload>
│   │       │       final signatureHistoryProvider = FutureProvider<List<SignatureEntity>>
│   │       │       final signatureDetailProvider = FutureProvider.family<Signature, int>
│   │       │       final confirmSignaturePaymentProvider = FutureProvider.family<Signature, (int id, String ref)>
│   │       ├── state/ [Vide]
│   │       └── widgets/
│   │
│   ├── publications/ [⚠️ SCAFFOLD - 1 page, 1 model, 5 providers]
│   │   ├── data/
│   │   │   ├── datasources/
│   │   │   │   └── publication_remote_datasource.dart
│   │   │   │       - getPublications(limit, offset, category) → List<PublicationModel>
│   │   │   │       - getPublicationDetails(id) → PublicationModel
│   │   │   │       - createPublication(payload) → PublicationModel
│   │   │   │       - updatePublication(id, payload) → PublicationModel
│   │   │   │       - deletePublication(id)
│   │   │   ├── models/
│   │   │   │   └── publication_model.dart
│   │   │   │       class PublicationModel(extends PublicationEntity)
│   │   │   └── repositories/
│   │   │       └── publication_repository_impl.dart
│   │   ├── domain/
│   │   │   ├── entities/
│   │   │   │   └── publication_entity.dart [30 lignes]
│   │   │   │       class PublicationEntity:
│   │   │   │         - id, titre, description
│   │   │   │         - auteur, dateCreation, category
│   │   │   │         - imageUrl?, viewCount
│   │   │   ├── repositories/ [Interface]
│   │   │   └── usecases/
│   │   └── presentation/
│   │       ├── pages/ [❌ À enrichir]
│   │       │   └── publications_page.dart (dans app/pages)
│   │       │       - Liste de publications avec infinite scroll
│   │       │       - Publication detail page (optionnel)
│   │       └── providers/
│   │           └── publication_providers.dart [70 lignes]
│   │               final publicationRemoteDatasourceProvider
│   │               final publicationRepositoryProvider
│   │               final publicationsProvider = FutureProvider.family<List<Publication>, (limit, offset, category?)>
│   │               final publicationDetailProvider = FutureProvider.family<Publication, int>
│   │               final createPublicationProvider = FutureProvider.family<Publication, PublicationPayload>
│   │               final updatePublicationProvider = FutureProvider.family<Publication, (id, payload)>
│   │               final deletePublicationProvider = FutureProvider.family<void, int>
│   │
│   ├── payment/ [❌ VIDE - À CRÉER]
│   │   ├── data/
│   │   ├── domain/
│   │   └── presentation/
│   │
│   └── user/ [❌ VIDE - À CRÉER]
│       ├── data/
│       ├── domain/
│       │   └── entities/
│       │       └── user_entity.dart [Partage avec auth/]
│       └── presentation/
│           ├── pages/ [❌ VIDE]
│           │   ├── profil_page.dart (dans app/pages - exists)
│           │   ├── settings_page.dart ❌ À CRÉER
│           │   └── statistics_page.dart ❌ À CRÉER
│           ├── providers/
│           │   └── user_providers.dart [Vide pour l'instant]
│           ├── state/ [Potentiel]
│           └── widgets/
│
└── shared/ [Utilitaires partagés]
    ├── extensions/            [Extensions Dart - TODO]
    ├── mixins/                [Mixins réutilisables - TODO]
    ├── models/
    │   └── base_model.dart [10 lignes]
    │       abstract BaseModel extends Equatable:
    │         - toJson() abstract
    │         - props getter
    ├── providers/              [❌ VIDE - Pas de global providers]
    └── widgets/
        ├── app_button.dart [100+ lignes] ✅
        ├── app_text_field.dart ✅
        ├── app_shimmer.dart [Skeleton loading] ✅
        └── app_snackbar.dart ✅
```

---

## ✅ ÉTAT D'IMPLÉMENTATION

### Résumé par Feature

| Feature | Pages | Models | Providers | State | Status |
|---------|-------|--------|-----------|-------|--------|
| **AUTH** | 3 ✅ | 4 ✅ | 5 ✅ | 6 ✅ | **✅ COMPLETE** |
| **CARDS** | 1 ✅ | 4 ✅ | 3 ✅ | - | **⚠️ PARTIAL** |
| **SIGNATURE** | 0 ❌ | 2 ✅ | 6 ✅ | - | **❌ INCOMPLETE** |
| **PUBLICATIONS** | 1 📝 | 1 📝 | 5 ✅ | - | **⚠️ SCAFFOLD** |
| **PAYMENT** | 0 ❌ | 0 ❌ | 0 ❌ | - | **❌ EMPTY** |
| **USER** | 0 ❌ | 0 ❌ | 1 📝 | - | **❌ EMPTY** |

### Détail par Catégorie

#### ✅ COMPLET - AUTH
- [x] 3 Pages (splash, login, forgot)
- [x] 6 États StateNotifier
- [x] 4 Modèles (UserEntity, UserModel, AuthModel, ChurchBrief)
- [x] 5 Providers (auth, apiClient, storage, datasource, repository)
- [x] Datasource + Repository complète
- [x] Intercepteur Auth (token + 401 handler)
- [x] Storage (token en SecureStorage)

#### ⚠️ PARTIELLE - CARDS
- [x] 1 Page (ma_carte_page.dart)
- [x] 4 Modèles (CardEntity, CardModel, SignatureHistoryEntity, Model)
- [x] 3 Providers (myCard, history, combined)
- [x] Datasource + Repository
- [ ] CardDetailPage (optionnel)
- [ ] Statistics / Analytics

#### ❌ INCOMPLÈTE - SIGNATURE
- [ ] 2 Pages manquantes:
  - [ ] sign_distance_page.dart
  - [ ] sign_presentiel_page.dart
- [x] 2 Modèles (SignatureEntity, SignatureModel)
- [x] 6 Providers (create distance, create presentiel, history, detail, confirm payment)
- [x] Datasource + Repository
- [ ] DTOs Payloads (SignatureDistancePayload, SignaturePresentielPayload)
- [ ] UI pour choix montant/méthode paiement

#### ⚠️ SCAFFOLD - PUBLICATIONS
- [ ] 1 Page (publications_page.dart dans app/pages - existe mais peut-être basique)
- [x] 1 Modèle (PublicationEntity - à vérifier)
- [x] 5 Providers (list, detail, create, update, delete)
- [ ] Infinite scroll / pagination
- [ ] Publication detail page

#### ❌ VIDE - PAYMENT
- [ ] Aucune page
- [ ] Aucun modèle
- [ ] Aucun provider
- [ ] À créer de zéro (CamPay? Orange Money API?)

#### ❌ VIDE - USER
- [ ] Aucune page (profil_page.dart existe dans app/pages)
- [ ] Aucun modèle spécifique
- [ ] user_providers.dart existe mais est vide
- [ ] À enrichir (settings, statistics, etc.)

---

## 📝 FICHIERS MANQUANTS

### À CRÉER PRIORITAIREMENT

#### 1. **Signature Pages** (HIGH PRIORITY)
```dart
// lib/features/signature/presentation/pages/sign_distance_page.dart
// Dépend: SignatureEntity, SignatureDistancePayload, signature_providers
// UI: Montants (500-10000), Méthode paiement, Notes, Bouton valider

// lib/features/signature/presentation/pages/sign_presentiel_page.dart
// Dépend: SignatureEntity, SignaturePresentielPayload, signature_providers
// UI: Liste membres, Montant, Méthode paiement, Confirmation caissier
```

#### 2. **Signature Payloads** (HIGH PRIORITY)
```dart
// lib/features/signature/domain/entities/signature_entity.dart (à augmenter)
// Ajouter classes:
//   class SignatureDistancePayload(amount, paymentMethod)
//   class SignaturePresentielPayload(amount, paymentMethod, notes)
```

#### 3. **Payment Feature** (MEDIUM PRIORITY)
```dart
// lib/features/payment/
// ├── data/
// │   ├── datasources/payment_remote_datasource.dart
// │   ├── models/payment_model.dart
// │   └── repositories/payment_repository_impl.dart
// ├── domain/
// │   ├── entities/payment_entity.dart
// │   ├── repositories/payment_repository.dart
// │   └── usecases/
// └── presentation/
//     ├── pages/
//     │   ├── payment_method_page.dart
//     │   ├── payment_confirmation_page.dart
//     │   └── payment_receipt_page.dart
//     ├── providers/payment_providers.dart
//     └── widgets/
```

#### 4. **User Enhancement** (MEDIUM PRIORITY)
```dart
// lib/features/user/presentation/pages/
// ├── profil_page.dart (enrichir celle de app/pages)
// ├── settings_page.dart
// │   - Notification preferences
// │   - Theme settings (à implémenter)
// │   - Language selection
// │   - Account security
// └── statistics_page.dart
//     - Graphiques (signatures, paiements)
//     - Historique complet

// lib/features/user/presentation/providers/user_providers.dart
// À implémenter avec:
//   - updateProfileProvider
//   - changePasswordProvider
//   - deleteAccountProvider
```

#### 5. **Doublon Router** (LOW PRIORITY - CLEANUP)
```dart
// Supprimer: lib/app/routes/app_router.dart
// C'est un doublon non utilisé de lib/app/router/app_router.dart
```

#### 6. **Optionnel - Améliorations**
```dart
// lib/core/errors/exceptions.dart
// - Exceptions personnalisées (NetworkException, ServerException, etc.)

// lib/core/errors/failures.dart
// - Failure classes avec Either pattern

// lib/shared/extensions/
// - String extensions (isEmpty, isValidEmail, etc.)
// - DateTime extensions (formatShort, formatLong)
// - List extensions (mapToModel, etc.)

// lib/shared/mixins/
// - FormValidationMixin
// - CacheableMixin
// - AnalyticsMixin

// Localization files
// - lib/core/localization/en.json
// - lib/core/localization/fr.json
// - Easy access via context.l10n
```

---

## 🎯 PATTERNS & BEST PRACTICES

### Clean Architecture Implementation

#### **Layers**
1. **Presentation** (Pages, Widgets, State Management)
   - Riverpod StateNotifier pour auth
   - Riverpod FutureProvider pour requêtes
   - ConsumerWidget pour accès aux providers

2. **Domain** (Business Logic)
   - Entities (Equatable, no Flutter deps)
   - Abstract Repositories
   - UseCases (optionnel ici, intégré dans providers)

3. **Data** (External APIs)
   - Remote DataSources
   - Models (sérialisation JSON)
   - Repository Implementations

#### **Pattern: Either / Result**
```dart
// Exemple auth
final result = await _repository.login(email, pwd);
result.fold(
  (failure) => state = AuthError(message: failure.message),
  (user) => state = AuthAuthenticated(user),
);
```

### Riverpod Patterns

#### **1. StateNotifier (Auth)**
```dart
final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final repository = ref.watch(authRepositoryProvider);
  return AuthNotifier(repository);
});

class AuthNotifier extends StateNotifier<AuthState> {
  AuthNotifier(this._repository) : super(const AuthInitial()) {
    _checkAuthStatus();
  }
  
  Future<void> login(String email, String password) async {
    state = const AuthLoading();
    final result = await _repository.login(email, password);
    result.fold(
      (failure) => state = AuthError(message: failure.message),
      (user) => state = AuthAuthenticated(user),
    );
  }
}
```

#### **2. FutureProvider (async queries)**
```dart
final myCardProvider = FutureProvider<CardEntity>((ref) async {
  final repository = ref.watch(cardRepositoryProvider);
  final result = await repository.getMyCard();
  return result.fold(
    (failure) => throw Exception(failure.message),
    (card) => card,
  );
});

// Usage in widget
final cardAsync = ref.watch(myCardProvider);
cardAsync.when(
  loading: () => _buildLoadingState(),
  error: (err, st) => _buildErrorState(err),
  data: (card) => _buildCardContent(card),
);
```

#### **3. FutureProvider.family (parameterized)**
```dart
final signatureDetailProvider = FutureProvider.family<SignatureEntity, int>((ref, id) async {
  final repository = ref.watch(signatureRepositoryProvider);
  final result = await repository.getSignatureDetails(id);
  return result.fold(
    (failure) => throw Exception(failure.message),
    (signature) => signature,
  );
});

// Usage
final signature = ref.watch(signatureDetailProvider(42));
```

### GoRouter Navigation Pattern

#### **Redirect Logic**
```dart
redirect: (context, state) {
  final isLoading = authState is AuthInitial || authState is AuthLoading;
  final isAuth = authState is AuthAuthenticated;
  final isSplash = state.matchedLocation == AppRoutes.splash;
  final isLoginPage = state.matchedLocation == AppRoutes.login || ...;

  // Priorité 1: Chargement initial → Splash
  if (isLoading && isSplash) return null;
  
  // Priorité 2: Connecté + auth page → Home
  if (isAuth && (isSplash || isLoginPage)) return AppRoutes.home;
  
  // Priorité 3: Non connecté + hors login → Login
  if (!isAuth && !isLoading && !isLoginPage && !isSplash) return AppRoutes.login;

  return null; // Pas de redirection
};
```

#### **Shell Navigation (Bottom Nav)**
```dart
ShellRoute(
  navigatorKey: _shellNavigatorKey,
  pageBuilder: (_, state, child) => NoTransitionPage(
    child: MainShell(child: child),
  ),
  routes: [
    GoRoute(path: AppRoutes.home, ...),
    GoRoute(path: AppRoutes.maCarte, ...),
    GoRoute(path: AppRoutes.signature, ...),
    GoRoute(path: AppRoutes.publications, ...),
    GoRoute(path: AppRoutes.profil, ...),
  ],
);
```

### API Client Pattern

#### **Singleton + Interceptors**
```dart
class ApiClient {
  static final ApiClient _instance = ApiClient._internal();
  factory ApiClient() => _instance;
  
  late final Dio _dio;
  
  ApiClient._internal() {
    _dio = Dio(BaseOptions(...));
    _dio.interceptors.addAll([
      _AuthInterceptor(),    // Token + 401 handling
      if (AppConfig.logNetworkRequests) _LogInterceptor(),
    ]);
  }
}

// Usage
final result = await apiClient.get<CardModel>('/cards/me');
```

---

## ⚙️ CONFIGURATION & SETUP

### pubspec.yaml - Dépendances Clés

```yaml
# Navigation
go_router: ^14.6.3

# State Management
flutter_riverpod: ^2.6.1
riverpod_annotation: ^2.6.1
build_runner: ^2.4.14
riverpod_generator: ^2.6.5

# Network
dio: ^5.7.0
pretty_dio_logger: ^1.4.0

# Storage
flutter_secure_storage: ^9.2.2
shared_preferences: ^2.3.3

# Fonctionnel
dartz: ^0.10.1  # Either / Result pattern
equatable: ^2.0.7

# UI
shimmer: ^3.0.0
cached_network_image: ^3.4.1
pinput: ^5.0.0  # OTP
smooth_page_indicator: ^1.2.0+3
lottie: ^3.3.1
flutter_animate: ^4.5.2
flutter_svg: ^2.0.16
```

### Environment Configuration

#### **AppConfig.dart**
```dart
static const _Env _env = _Env.dev;  // Change ici pour dev/staging/prod

static String get apiBaseUrl {
  switch (_env) {
    case _Env.dev:
      return 'http://10.0.2.2:8000/api/v1';  // Émulateur
    case _Env.staging:
      return 'https://staging-api.eec.cm/api/v1';
    case _Env.prod:
      return 'https://api.eec.cm/api/v1';
  }
}
```

### Color Palette Reference

```dart
// Violet EEC
Primary:       #4A1C7C
Primary Light: #7B3FBF
Primary Dark:  #2D0F50

// Or Liturgique
Secondary:     #D4AF37
Secondary Light: #F5D76E

// Accent
Accent:        #9C27B0

// Fonctionnel
Success:  #2E7D32
Error:    #C62828
Warning:  #E65100
Info:     #0277BD

// Neutres
Background: #F8F5FF
Surface:    #FFFFFF
TextPrimary: #1A0530
TextSecondary: #6B5B82
Divider:    #E8E0F0
```

---

## 🚀 PROCHAINES ÉTAPES

### Phase 1: COMPLETION (1-2 semaines)
- [x] ✅ Analyser la structure existante
- [ ] **PRIORITY 1**: Créer `sign_distance_page.dart`
- [ ] **PRIORITY 1**: Créer `sign_presentiel_page.dart`
- [ ] **PRIORITY 2**: Ajouter `SignatureDistancePayload` + `SignaturePresentielPayload`
- [ ] **PRIORITY 3**: Enrichir `ma_carte_page.dart` (mieux afficher historique)
- [ ] Tester screens auth complètement

### Phase 2: FEATURES (2-3 semaines)
- [ ] Créer `payment/` feature complète (CamPay intégration?)
- [ ] Enrichir `user/` feature (settings, stats)
- [ ] Améliorer `publications/` (pagination, infinite scroll, detail page)
- [ ] Implémenter theme mode toggle (dark/light from preferences)

### Phase 3: TESTING & POLISH (1-2 semaines)
- [ ] Unit tests pour providers Riverpod
- [ ] Integration tests pour navigation
- [ ] E2E tests pour user flows (login → signature → payment)
- [ ] Optimiser images assets
- [ ] Améliorer messages d'erreur (i18n)

### Phase 4: DEPLOYMENT
- [ ] Build APK pour Android (dev, staging, prod)
- [ ] TestFlight pour iOS (si applicable)
- [ ] CI/CD (GitHub Actions, Fastlane)
- [ ] Crash reporting (Firebase Crashlytics)
- [ ] Analytics (Firebase Analytics)

---

## 📚 REFERENCES & RESOURCES

### Documentation Officielle
- [Flutter Docs](https://flutter.dev/docs)
- [Riverpod Docs](https://riverpod.dev)
- [GoRouter Docs](https://pub.dev/packages/go_router)
- [Dio Docs](https://github.com/flutterchina/dio)

### Architecture Patterns
- [Clean Architecture](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Repository Pattern](https://www.martinfowler.com/eaaCatalog/repository.html)
- [Either / Result Pattern](https://pub.dev/packages/dartz)

### Flutter Community
- [FlutterAwesome](https://flutterawesome.com)
- [Pub.dev Packages](https://pub.dev)
- [Reddit r/FlutterDev](https://reddit.com/r/FlutterDev)

---

**FIN DE L'ANALYSE**

*Document généré le 22 Avril 2026*  
*Pour des clarifications, consulter la documentation du projet ou contacter l'équipe dev*
