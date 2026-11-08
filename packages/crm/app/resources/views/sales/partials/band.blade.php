@php
    $bandClass = match ($prospect->revenue_band) {
        \App\Models\Prospect::BAND_FULL_SERVICE => 'band--full',
        \App\Models\Prospect::BAND_RETAINER => 'band--retainer',
        \App\Models\Prospect::BAND_ENTRY => 'band--entry',
        default => 'band--nurture',
    };
@endphp
<span class="band {{ $bandClass }}">{{ $prospect->bandLabel() }}</span>
