# 🚀 QUICK START - Code à Copier-Coller

**Objectif**: Commencer Publication Module en moins de 1 heure  
**Difficulté**: ⭐ Easy (copy-paste structure existing)

---

## 📋 STRUCTURE À CRÉER

```
lib/features/publication/
├── domain/
│   └── entities/
│       └── publication_entity.dart
├── data/
│   ├── datasources/
│   ├── models/
│   │   └── publication_model.dart
│   └── repositories/
├── presentation/
│   ├── providers/
│   │   └── publications_provider.dart
│   └── screens/
│       ├── publications_list_screen.dart
│       └── publication_detail_screen.dart
```

---

## 1️⃣ publication_entity.dart

**Copié depuis**: `domain/entities/user_entity.dart` (structure similaire)

```dart
// lib/features/publication/domain/entities/publication_entity.dart

class PublicationEntity {
  final String id;
  final String churchId;
  final String title;
  final String content;
  final String? imageUrl;
  final DateTime createdAt;
  final DateTime updatedAt;

  PublicationEntity({
    required this.id,
    required this.churchId,
    required this.title,
    required this.content,
    this.imageUrl,
    required this.createdAt,
    required this.updatedAt,
  });
}
```

---

## 2️⃣ publication_model.dart

**Copié depuis**: `data/models/user_model.dart` (structure similaire)

```dart
// lib/features/publication/data/models/publication_model.dart

import 'package:json_annotation/json_annotation.dart';

part 'publication_model.g.dart';

@JsonSerializable()
class PublicationModel {
  @JsonKey(name: 'id')
  final String id;
  
  @JsonKey(name: 'church_id')
  final String churchId;
  
  @JsonKey(name: 'title')
  final String title;
  
  @JsonKey(name: 'content')
  final String content;
  
  @JsonKey(name: 'image_url')
  final String? imageUrl;
  
  @JsonKey(name: 'created_at')
  final DateTime createdAt;
  
  @JsonKey(name: 'updated_at')
  final DateTime updatedAt;

  PublicationModel({
    required this.id,
    required this.churchId,
    required this.title,
    required this.content,
    this.imageUrl,
    required this.createdAt,
    required this.updatedAt,
  });

  factory PublicationModel.fromJson(Map<String, dynamic> json) =>
      _$PublicationModelFromJson(json);

  Map<String, dynamic> toJson() => _$PublicationModelToJson(this);
}
```

---

## 3️⃣ publications_provider.dart

**Copié depuis**: `data/providers/users_provider.dart` (adapté pour publications)

```dart
// lib/features/publication/presentation/providers/publications_provider.dart

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:dio/dio.dart';
import 'publication_entity.dart';
import 'publication_model.dart';

final publicationsProvider = FutureProvider<List<PublicationEntity>>((ref) async {
  // Adapter selon votre API client
  const baseUrl = 'http://localhost:8000/api/v1';
  final dio = Dio(BaseOptions(baseUrl: baseUrl));
  
  try {
    final response = await dio.get('/publications');
    final publications = (response.data['data'] as List)
        .map((p) => PublicationModel.fromJson(p))
        .toList();
    
    return publications.map((m) => PublicationEntity(
      id: m.id,
      churchId: m.churchId,
      title: m.title,
      content: m.content,
      imageUrl: m.imageUrl,
      createdAt: m.createdAt,
      updatedAt: m.updatedAt,
    )).toList();
  } catch (e) {
    throw Exception('Failed to fetch publications: $e');
  }
});

final publicationDetailProvider = FutureProvider.family<PublicationEntity, String>((ref, id) async {
  const baseUrl = 'http://localhost:8000/api/v1';
  final dio = Dio(BaseOptions(baseUrl: baseUrl));
  
  try {
    final response = await dio.get('/publications/$id');
    final publication = PublicationModel.fromJson(response.data['data']);
    
    return PublicationEntity(
      id: publication.id,
      churchId: publication.churchId,
      title: publication.title,
      content: publication.content,
      imageUrl: publication.imageUrl,
      createdAt: publication.createdAt,
      updatedAt: publication.updatedAt,
    );
  } catch (e) {
    throw Exception('Failed to fetch publication: $e');
  }
});
```

---

## 4️⃣ publications_list_screen.dart

**Copié depuis**: `presentation/screens/users_screen.dart` (adapté)

```dart
// lib/features/publication/presentation/screens/publications_list_screen.dart

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/publications_provider.dart';

class PublicationsListScreen extends ConsumerWidget {
  const PublicationsListScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final publicationsAsync = ref.watch(publicationsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Publications'),
        centerTitle: true,
      ),
      body: publicationsAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, stackTrace) => Center(
          child: Text('Error: $error'),
        ),
        data: (publications) {
          if (publications.isEmpty) {
            return const Center(
              child: Text('No publications available'),
            );
          }

          return ListView.builder(
            itemCount: publications.length,
            itemBuilder: (context, index) {
              final publication = publications[index];
              
              return ListTile(
                title: Text(publication.title),
                subtitle: Text(
                  publication.content.length > 100
                      ? '${publication.content.substring(0, 100)}...'
                      : publication.content,
                ),
                trailing: Icon(Icons.arrow_forward_ios),
                onTap: () {
                  // Navigate to detail screen
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => PublicationDetailScreen(
                        publicationId: publication.id,
                      ),
                    ),
                  );
                },
              );
            },
          );
        },
      ),
    );
  }
}
```

---

## 5️⃣ publication_detail_screen.dart

**Copié depuis**: `presentation/screens/user_detail_screen.dart` (adapté)

```dart
// lib/features/publication/presentation/screens/publication_detail_screen.dart

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/publications_provider.dart';

class PublicationDetailScreen extends ConsumerWidget {
  final String publicationId;

  const PublicationDetailScreen({
    Key? key,
    required this.publicationId,
  }) : super(key: key);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final publicationAsync = ref.watch(
      publicationDetailProvider(publicationId),
    );

    return Scaffold(
      appBar: AppBar(
        title: const Text('Publication Details'),
        centerTitle: true,
      ),
      body: publicationAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, stackTrace) => Center(
          child: Text('Error: $error'),
        ),
        data: (publication) {
          return SingleChildScrollView(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Image if available
                if (publication.imageUrl != null)
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8.0),
                    child: Image.network(
                      publication.imageUrl!,
                      width: double.infinity,
                      height: 200,
                      fit: BoxFit.cover,
                    ),
                  ),
                const SizedBox(height: 16),

                // Title
                Text(
                  publication.title,
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 8),

                // Date
                Text(
                  'Published: ${publication.createdAt.toLocal().toString().split('.')[0]}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Colors.grey,
                  ),
                ),
                const SizedBox(height: 16),

                // Content
                Text(
                  publication.content,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
```

---

## 🔧 NEXT STEPS

### 1. Ajouter les fichiers dans lib/features/publication/

```bash
# Create directories
mkdir -p lib/features/publication/domain/entities
mkdir -p lib/features/publication/data/models
mkdir -p lib/features/publication/data/datasources
mkdir -p lib/features/publication/data/repositories
mkdir -p lib/features/publication/presentation/providers
mkdir -p lib/features/publication/presentation/screens
```

### 2. Copier-coller le code ci-dessus dans les fichiers correspondants

### 3. Générer le code JSON (si vous utilisez json_serializable)

```bash
flutter pub run build_runner build
```

### 4. Ajouter la route dans app_router.dart

```dart
GoRoute(
  path: 'publications',
  builder: (context, state) => const PublicationsListScreen(),
),
```

### 5. Ajouter le menu dans le drawer

```dart
ListTile(
  title: const Text('Publications'),
  onTap: () => context.push('/publications'),
),
```

### 6. Tester

```bash
flutter run
```

---

## 📝 POINTS IMPORTANTS

1. **API Endpoint**: Adapter `baseUrl` selon votre environnement
2. **Authentication**: Ajouter les headers d'authentification si nécessaire
3. **Error handling**: Améliorer la gestion d'erreurs selon vos besoins
4. **Styling**: Adapter le design selon votre app
5. **Riverpod**: Utiliser les providers existants de l'app

---

## ⏱️ TEMPS ESTIMÉ

- Créer les fichiers: 5 min
- Copier le code: 5 min
- Générer JSON code: 2 min
- Ajouter routes: 3 min
- Tester: 10 min
- **Total: 25 minutes**

---

## 🎯 APRÈS PUBLICATION

Suivre la même structure pour:
1. **Finance Reports** (améliorer finances_screen.dart)
2. **Church Management** (nouvelle feature, copier cette structure)
3. **Payment Management** (nouvelle feature, copier cette structure)

---

**Prêt à coder?** Commencez maintenant!

