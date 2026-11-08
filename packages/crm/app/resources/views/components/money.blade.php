@props(['cents' => 0])
R$ {{ number_format(((int) $cents) / 100, 2, ',', '.') }}
