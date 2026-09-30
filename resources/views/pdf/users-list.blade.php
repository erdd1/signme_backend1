<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Membres — {{ $church?->nom ?? 'SignMe' }}</title>
    @include('pdf.partials.header-styles')
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        table.membres { width: 100%; border-collapse: collapse; }
        table.membres th, table.membres td { padding: 5px 6px; border-bottom: 1px solid #e0e0e0; text-align: left; vertical-align: top; }
        table.membres th { background: #001F3F; color: #ffffff; font-size: 10px; }
        table.membres tr:nth-child(even) td { background: #f7f9fc; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: bold; }
        .badge-actif { background: #dcfce7; color: #15803d; }
        .badge-inactif { background: #fee2e2; color: #b91c1c; }
        .badge-communiant { background: #dbeafe; color: #1d4ed8; }
        .badge-non-communiant { background: #fef3c7; color: #b45309; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'title' => 'Liste des membres',
        'subtitle' => $total . ' membre(s) enregistré(s)',
    ])

    <table class="membres">
        <thead>
            <tr>
                <th>Identifiant</th>
                <th>Nom</th>
                <th>Sexe</th>
                <th>Rôle</th>
                <th>Groupe</th>
                <th>Date de naissance</th>
                <th>Quartier</th>
                <th>Téléphone</th>
                <th>Statut</th>
                <th>Communiant</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($membres as $membre)
                <tr>
                    <td>{{ $membre->matricule ?? '—' }}</td>
                    <td>{{ $membre->nom }}</td>
                    <td>{{ $membre->sexe === 'femme' ? 'Femme' : ($membre->sexe === 'homme' ? 'Homme' : '—') }}</td>
                    <td>{{ ucfirst($membre->role) }}</td>
                    <td>{{ $membre->groupePrincipal?->nom ?? '—' }}</td>
                    <td>{{ $membre->dateNaissance?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $membre->quartier?->nom ?? '—' }}</td>
                    <td>{{ $membre->telephone ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $membre->actif ? 'badge-actif' : 'badge-inactif' }}">
                            {{ $membre->actif ? 'Actif' : 'Inactif' }}
                        </span>
                    </td>
                    <td>
                        <span class="badge {{ $membre->estCommuniant ? 'badge-communiant' : 'badge-non-communiant' }}">
                            {{ $membre->estCommuniant ? 'Communiant' : 'Non communiant' }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('pdf.partials.footer')
</body>
</html>
