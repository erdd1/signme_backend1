<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu — {{ $detail['referenceInterne'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .subtitle { color: #555; margin-bottom: 20px; }
        table.fields { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.fields td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
        table.fields td.label { width: 40%; color: #555; }
        table.fields td.value { font-weight: bold; }
        .status-completed { color: #15803d; }
        .status-pending { color: #b45309; }
        .status-failed { color: #b91c1c; }
        h2 { font-size: 14px; margin-top: 24px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        table.events { width: 100%; border-collapse: collapse; }
        table.events th, table.events td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 11px; }
        table.events th { background: #f5f5f5; }
        .footer { margin-top: 30px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    <h1>Reçu de paiement — {{ $type === 'contribution' ? 'Contribution événement' : 'Carte de signature' }}</h1>
    <p class="subtitle">Référence interne : {{ $detail['referenceInterne'] }}</p>

    <table class="fields">
        <tr>
            <td class="label">Référence interne SignMe</td>
            <td class="value">{{ $detail['referenceInterne'] }}</td>
        </tr>
        <tr>
            <td class="label">Référence NotchPay</td>
            <td class="value">{{ $detail['referenceNotchPay'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">ID de transaction (gateway)</td>
            <td class="value">{{ $detail['transactionIdGateway'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Date de création</td>
            <td class="value">{{ $detail['createdAt'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Date de confirmation</td>
            <td class="value">{{ $detail['confirmedAt'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Moyen de paiement</td>
            <td class="value">{{ $detail['paymentMethod'] }}{{ $detail['paymentOperator'] ? ' — ' . $detail['paymentOperator'] : '' }}</td>
        </tr>
        <tr>
            <td class="label">Numéro (masqué)</td>
            <td class="value">{{ $detail['paymentNumberMasked'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Frais</td>
            <td class="value">{{ number_format($detail['fees'], 0, ',', ' ') }} FCFA</td>
        </tr>
        <tr>
            <td class="label">Montant net (débité)</td>
            <td class="value">{{ number_format($detail['netAmount'], 0, ',', ' ') }} FCFA</td>
        </tr>
        <tr>
            <td class="label">Statut</td>
            <td class="value status-{{ $detail['status'] }}">{{ strtoupper($detail['status']) }}</td>
        </tr>
    </table>

    <h2>Historique des événements</h2>
    @if (count($events) === 0)
        <p>Aucun événement enregistré pour cette transaction.</p>
    @else
        <table class="events">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Statut résultant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ $event->resulting_status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer">Document généré le {{ now()->format('Y-m-d H:i:s') }} — SignMe EEC.</p>
</body>
</html>
