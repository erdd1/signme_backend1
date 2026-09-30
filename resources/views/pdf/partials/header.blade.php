@php
    $logoPath = resource_path('pdf-assets/eec-logo.jpg');
    $logoBase64 = file_exists($logoPath)
        ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath))
        : null;
@endphp

<table class="pdf-header">
    <tr>
        @if ($logoBase64)
            <td class="pdf-header-logo">
                <img src="{{ $logoBase64 }}" width="56" height="52">
            </td>
        @endif
        <td class="pdf-header-identity">
            <div class="pdf-header-denomination">Église Évangélique du Cameroun</div>
            <div class="pdf-header-church">{{ $church?->nom ?? '—' }}</div>
            @if ($church && ($church->adresse || $church->ville))
                <div class="pdf-header-contact">
                    {{ collect([$church->adresse, $church->ville])->filter()->implode(', ') }}
                </div>
            @endif
            @if ($church && ($church->telephone || $church->email))
                <div class="pdf-header-contact">
                    {{ collect([
                        $church->telephone ? 'Tél. ' . $church->telephone : null,
                        $church->email,
                    ])->filter()->implode(' — ') }}
                </div>
            @endif
        </td>
        <td class="pdf-header-meta">
            <div class="pdf-header-app">SignMe</div>
            <div class="pdf-header-date">{{ now()->format('d/m/Y à H:i') }}</div>
        </td>
    </tr>
</table>

<div class="pdf-header-rule"></div>

<div class="pdf-title-block">
    <div class="pdf-title">{{ $title }}</div>
    @if (!empty($subtitle))
        <div class="pdf-subtitle">{{ $subtitle }}</div>
    @endif
</div>

<div class="pdf-header-rule pdf-header-rule-thin"></div>
