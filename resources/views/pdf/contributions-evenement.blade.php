<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Contributions événement — {{ $church?->nom ?? 'SignMe' }}</title>
    @include('pdf.partials.header-styles')
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        table.stats { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.stats td { padding: 8px 10px; border: 1px solid #e0e0e0; text-align: center; }
        table.stats td .stat-value { display: block; font-size: 15px; font-weight: bold; color: #001F3F; }
        table.stats td .stat-label { display: block; font-size: 9px; color: #555; margin-top: 2px; }
        h2 { font-size: 13px; margin-top: 22px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; color: #001F3F; }
        table.contributions { width: 100%; border-collapse: collapse; }
        table.contributions th, table.contributions td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
        table.contributions th { background: #f5f5f5; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'title' => 'Contributions événement',
        'subtitle' => $evenementNom ?? 'Tous les événements confondus',
    ])

    <table class="stats">
        <tr>
            <td>
                <span class="stat-value">{{ $stats['count'] }}</span>
                <span class="stat-label">Contributions</span>
            </td>
            <td>
                <span class="stat-value">{{ $stats['nombre_contributeurs'] }}</span>
                <span class="stat-label">Contributeurs</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($stats['total_paye'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Total payé</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($stats['total_frais'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Frais</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($stats['net_recu'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Net reçu par l'église</span>
            </td>
        </tr>
    </table>

    <h2>Détail des contributions ({{ $contributions->count() }})</h2>
    @if ($contributions->isEmpty())
        <p>Aucune contribution complétée pour cette sélection.</p>
    @else
        <table class="contributions">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Contributeur</th>
                    <th>Événement</th>
                    <th>Groupe</th>
                    <th>Montant</th>
                    <th>Frais</th>
                    <th>Total payé</th>
                    <th>Moyen</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($contributions as $c)
                    <tr>
                        <td>{{ $c->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $c->user?->nom ?? '—' }}</td>
                        <td>{{ $c->evenement?->titre ?? '—' }}</td>
                        <td>{{ $c->groupe?->nom ?? '—' }}</td>
                        <td>{{ number_format($c->montant, 0, ',', ' ') }} F</td>
                        <td>{{ number_format($c->frais, 0, ',', ' ') }} F</td>
                        <td>{{ number_format($c->montantTotal, 0, ',', ' ') }} F</td>
                        <td>{{ $c->paymentMethod === 'card' ? 'Carte' : 'Mobile Money' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
