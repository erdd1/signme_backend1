<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport secrétaire — {{ $monthLabel }}</title>
    @include('pdf.partials.header-styles')
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        .lead-note { color: #555; margin-bottom: 16px; }
        table.stats { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.stats td { padding: 8px 10px; border: 1px solid #e0e0e0; text-align: center; }
        table.stats td .stat-value { display: block; font-size: 16px; font-weight: bold; color: #001F3F; }
        table.stats td .stat-label { display: block; font-size: 9px; color: #555; margin-top: 2px; }
        h2 { font-size: 13px; margin-top: 22px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; color: #001F3F; }
        table.membres { width: 100%; border-collapse: collapse; }
        table.membres th, table.membres td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
        table.membres th { background: #f5f5f5; }
        .signed { color: #15803d; font-weight: bold; }
        .not-signed { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'title' => 'Rapport secrétaire',
        'subtitle' => $monthLabel,
    ])

    <table class="stats">
        <tr>
            <td>
                <span class="stat-value">{{ $data['total_fideles'] }}</span>
                <span class="stat-label">Fidèles actifs</span>
            </td>
            <td>
                <span class="stat-value">{{ $data['total_membres'] }}</span>
                <span class="stat-label">Communiants</span>
            </td>
            <td>
                <span class="stat-value">{{ $data['total_signes'] }}</span>
                <span class="stat-label">Ont signé</span>
            </td>
            <td>
                <span class="stat-value">{{ $data['total_non_signes'] }}</span>
                <span class="stat-label">N'ont pas signé</span>
            </td>
            <td>
                <span class="stat-value">{{ $data['taux_signature'] }}%</span>
                <span class="stat-label">Taux de signature</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['montant_collecte'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Montant collecté</span>
            </td>
        </tr>
    </table>
    <p class="lead-note">
        {{ $data['total_membres'] }} communiant(s) sur {{ $data['total_fideles'] }} fidèle(s) —
        les paroissiens non communiants ne sont pas comptabilisés dans le suivi des signatures.
    </p>

    <h2>Ont signé ({{ $data['total_signes'] }})</h2>
    @if (count($data['signes']) === 0)
        <p>Aucun membre n'a signé ce mois-ci.</p>
    @else
        <table class="membres">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Rôle</th>
                    <th>Type</th>
                    <th>Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['signes'] as $membre)
                    <tr>
                        <td class="signed">{{ $membre['nom'] }}</td>
                        <td>{{ ucfirst($membre['role']) }}</td>
                        <td>{{ ($membre['signature']['signatureType'] ?? '') === 'presentiel' ? 'Présentiel' : 'Distance' }}</td>
                        <td>{{ number_format($membre['signature']['amount'] ?? 0, 0, ',', ' ') }} F</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>N'ont pas signé ({{ $data['total_non_signes'] }})</h2>
    @if (count($data['non_signes']) === 0)
        <p>Tous les communiants ont signé ce mois-ci.</p>
    @else
        <table class="membres">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Rôle</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['non_signes'] as $membre)
                    <tr>
                        <td class="not-signed">{{ $membre['nom'] }}</td>
                        <td>{{ ucfirst($membre['role']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
