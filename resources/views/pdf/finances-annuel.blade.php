<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport financier {{ $data['year'] }}</title>
    @include('pdf.partials.header-styles')
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        table.totaux { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.totaux td { padding: 8px 10px; border: 1px solid #e0e0e0; text-align: center; }
        table.totaux td .stat-value { display: block; font-size: 15px; font-weight: bold; color: #001F3F; }
        table.totaux td .stat-label { display: block; font-size: 9px; color: #555; margin-top: 2px; }
        h2 { font-size: 13px; margin-top: 22px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; color: #001F3F; }
        table.mois { width: 100%; border-collapse: collapse; }
        table.mois th, table.mois td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: right; font-size: 10px; }
        table.mois th { background: #f5f5f5; text-align: right; }
        table.mois th:first-child, table.mois td:first-child { text-align: left; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'title' => 'Rapport financier annuel',
        'subtitle' => 'Exercice ' . $data['year'],
    ])

    <table class="totaux">
        <tr>
            <td>
                <span class="stat-value">{{ $data['totaux_annuels']['nb_signatures'] }}</span>
                <span class="stat-label">Signatures</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['totaux_annuels']['total_offrandes'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Offrandes</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['totaux_annuels']['total_frais'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Frais</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['totaux_annuels']['total_encaisse'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Total encaissé</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['totaux_annuels']['total_presentiel'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Présentiel</span>
            </td>
            <td>
                <span class="stat-value">{{ number_format($data['totaux_annuels']['total_distance'], 0, ',', ' ') }} F</span>
                <span class="stat-label">Distance</span>
            </td>
        </tr>
    </table>

    <h2>Détail mensuel</h2>
    <table class="mois">
        <thead>
            <tr>
                <th>Mois</th>
                <th>Signatures</th>
                <th>Offrandes</th>
                <th>Frais</th>
                <th>Total encaissé</th>
                <th>Présentiel</th>
                <th>Distance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['mois'] as $mois)
                <tr>
                    <td>{{ $mois['monthName'] }}</td>
                    <td>{{ $mois['nb_signatures'] }}</td>
                    <td>{{ number_format($mois['total_offrandes'], 0, ',', ' ') }} F</td>
                    <td>{{ number_format($mois['total_frais'], 0, ',', ' ') }} F</td>
                    <td>{{ number_format($mois['total_encaisse'], 0, ',', ' ') }} F</td>
                    <td>{{ number_format($mois['montant_presentiel'], 0, ',', ' ') }} F</td>
                    <td>{{ number_format($mois['montant_distance'], 0, ',', ' ') }} F</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('pdf.partials.footer')
</body>
</html>
