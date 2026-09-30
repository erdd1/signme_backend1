<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    @php
        $logoUrl = asset('images/eec-logo.jpg');
        $ogImage = $evenement?->imageUrl ?: $logoUrl;
        $ogTitle = $evenement ? $evenement->titre : 'Événement introuvable — SignMe';
        $ogDescription = $evenement
            ? \Illuminate\Support\Str::limit(strip_tags($evenement->description), 200)
            : "Cet événement n'est plus disponible ou a été retiré.";
        $packageName = config('services.mobile_app.android_package_name');
        $playStoreUrl = config('services.mobile_app.play_store_url');
        $appStoreUrl = config('services.mobile_app.app_store_url');
    @endphp

    <title>{{ $ogTitle }} — SignMe</title>

    {{-- Open Graph (WhatsApp, Facebook, Instagram, LinkedIn...) --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="SignMe">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:url" content="{{ $shareUrl }}">

    {{-- Twitter / X Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    {{-- Android App Links / iOS Universal Links : si l'app est installée et
         le domaine vérifié, l'OS ouvre l'app AVANT même que cette page ne
         charge — tout ce qui suit n'est vu que dans le cas contraire. --}}

    <style>
        :root {
            --primary-dark: #001F3F;
            --primary-light: #2979F0;
            --bg: #F4F6FA;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: #1a1a1a;
            display: flex;
            justify-content: center;
            padding: 32px 16px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 31, 63, 0.12);
        }
        .card-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));
        }
        .card-body { padding: 24px; }
        .brand {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .brand img { width: 28px; height: 28px; border-radius: 6px; }
        .brand span { font-weight: 700; color: var(--primary-light); font-size: 15px; }
        .church-name {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            margin-bottom: 4px;
        }
        h1 { font-size: 21px; margin: 0 0 10px; color: var(--primary-dark); line-height: 1.3; }
        .date-badge {
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-light);
            background: rgba(41, 121, 240, 0.1);
            padding: 4px 10px;
            border-radius: 20px;
            margin-bottom: 14px;
        }
        p.description { font-size: 14px; line-height: 1.6; color: #444; margin-bottom: 24px; white-space: pre-line; }
        .cta {
            display: block;
            text-align: center;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            margin-bottom: 10px;
        }
        .cta-primary {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));
            color: #fff;
        }
        .cta-secondary {
            background: #fff;
            color: var(--primary-light);
            border: 1.5px solid var(--primary-light);
        }
        .hint { font-size: 12px; color: #999; text-align: center; margin-top: 16px; line-height: 1.5; }
        .not-found { text-align: center; padding: 40px 24px; }
        .not-found img { width: 56px; height: 56px; margin-bottom: 16px; opacity: 0.6; }
    </style>
</head>
<body>
    <div class="card">
        @if ($evenement)
            <img class="card-image" src="{{ $ogImage }}" alt="{{ $evenement->titre }}">
            <div class="card-body">
                <div class="brand">
                    <img src="{{ $logoUrl }}" alt="SignMe">
                    <span>SignMe</span>
                </div>
                @if ($evenement->church)
                    <div class="church-name">{{ $evenement->church->nom }}</div>
                @endif
                <h1>{{ $evenement->titre }}</h1>
                <span class="date-badge">{{ $evenement->dateDebut?->format('d/m/Y') }}</span>
                <p class="description">{{ \Illuminate\Support\Str::limit(strip_tags($evenement->description), 280) }}</p>

                <a class="cta cta-primary" href="{{ $shareUrl }}">Ouvrir dans l'application SignMe</a>
                @if ($playStoreUrl)
                    <a class="cta cta-secondary" href="{{ $playStoreUrl }}">Télécharger sur Google Play</a>
                @endif
                @if ($appStoreUrl)
                    <a class="cta cta-secondary" href="{{ $appStoreUrl }}">Télécharger sur l'App Store</a>
                @endif

                <p class="hint">
                    Si SignMe est déjà installée sur votre téléphone, ce lien l'ouvre
                    automatiquement sur cet événement.
                </p>
            </div>
        @else
            <div class="not-found">
                <img src="{{ $logoUrl }}" alt="SignMe">
                <h1>Événement introuvable</h1>
                <p class="description">Cet événement n'existe plus ou n'est plus disponible.</p>
                @if ($playStoreUrl)
                    <a class="cta cta-primary" href="{{ $playStoreUrl }}">Télécharger SignMe</a>
                @endif
            </div>
        @endif
    </div>
</body>
</html>
