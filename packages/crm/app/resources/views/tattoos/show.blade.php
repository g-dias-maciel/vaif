@extends('layouts.app')

@section('title', $tattoo->lead?->nome ?: 'Projeto')

@php
    $tipo = $tattoo->tipo_tatuagem;
    $coverUpLabel = $tipo === 'cobertura'
        ? 'Sim (cobertura)'
        : ($tipo === 'nova' ? 'Não (tatuagem nova)' : ($tipo ?: '—'));
    $promoQuote = $tattoo->lead?->quoteFor($tattoo->placement, $tattoo->body_zone);
@endphp

@section('content')
<a href="{{ route('dashboard') }}" class="back">&larr; Voltar</a>
<h1 class="page-title">
    {{ $tattoo->lead?->nome ?: 'Sem nome' }}
    @if ($tattoo->lead?->is_returning) <span class="tag">Recorrente</span> @endif
</h1>

<div class="panel">
    <div class="stat-grid" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="stat-label">Status</div>
            <div class="stat-value" style="font-size: 1.2rem;">{{ $pipeline[$tattoo->pipeline_status] ?? $tattoo->pipeline_status }}</div>
        </div>
        @if ($tattoo->charged_price_cents !== null)
            <div class="stat-card">
                <div class="stat-label">Preço</div>
                <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($tattoo->charged_price_cents / 100, 2, ',', '.') }}</div>
            </div>
        @endif
        @if ($tattoo->deposit_amount)
            <div class="stat-card">
                <div class="stat-label">Sinal</div>
                <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($tattoo->deposit_amount / 100, 2, ',', '.') }}</div>
            </div>
        @endif
        @if ($tattoo->booked_date)
            <div class="stat-card">
                <div class="stat-label">Agendado</div>
                <div class="stat-value" style="font-size: 1.2rem;">{{ $tattoo->booked_date->format('d/m/Y H:i') }}</div>
            </div>
        @endif
    </div>

    <h2 class="section-title" style="margin-top: 0;">Detalhes do projeto</h2>
    <div class="detail-grid">
        <div><span class="meta">Primeira tatuagem</span><div>{{ $tattoo->primeira_tatuagem === null ? '—' : ($tattoo->primeira_tatuagem ? 'Sim' : 'Não') }}</div></div>
        <div><span class="meta">Cobertura</span><div>{{ $coverUpLabel }}</div></div>
        <div><span class="meta">Local</span><div>{{ $tattoo->placement ?: '—' }}</div></div>
        <div><span class="meta">Tamanho / zona</span><div>{{ $tattoo->body_zone ?: '—' }}</div></div>
        <div><span class="meta">Estilo</span><div>{{ $tattoo->style ?: '—' }}</div></div>
        <div><span class="meta">Telefone</span><div>{{ $tattoo->lead?->telefone }}</div></div>
        <div><span class="meta">Origem</span><div>{{ $tattoo->lead?->lead_source ?: '—' }}</div></div>
        <div><span class="meta">Depósito</span><div>{{ $tattoo->deposit_status ?: '—' }}</div></div>
    </div>

    @if ($promoQuote)
        <div class="promo-row {{ $promoQuote->isOpen() ? 'is-active' : '' }}" style="margin-top: 16px;">
            <div class="promo-main">
                <strong>Promoção: {{ $promoQuote->promotion?->name ?: 'oferta' }}</strong>
                <span class="meta">
                    1ª tatuagem {{ $promoQuote->firstPriceLabel() }}
                    · 2ª até {{ $promoQuote->secondCapLabel() }}
                    @if ($promoQuote->valid_until) · válida até {{ $promoQuote->valid_until->format('d/m/Y') }} @endif
                </span>
            </div>
            <span class="tag {{ $promoQuote->isOpen() ? 'tag-success' : 'tag-muted' }}">{{ $promoQuote->statusLabel() }}</span>
        </div>
    @endif

    @if ($tattoo->significado)
        <div style="margin-top: 16px;">
            <span class="meta">Significado</span>
            <p style="margin-top: 4px;">{{ $tattoo->significado }}</p>
        </div>
    @endif

    @if ($tattoo->reference_pics)
        <h2 class="section-title">Fotos de referência</h2>
        <div class="ref-grid">
            @foreach ($tattoo->reference_pics as $img)
                <a href="{{ $img }}" target="_blank" rel="noopener"><img src="{{ $img }}" alt="Referência"></a>
            @endforeach
        </div>
    @endif

    <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        @if ($tattoo->lead?->whatsapp_url)
            <a href="{{ $tattoo->lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp">WhatsApp</a>
        @endif
        @if ($tattoo->lead)
            <a href="{{ route('leads.show', $tattoo->lead) }}" class="btn btn--ghost">Ver cliente</a>
        @endif
        @if ($tattoo->pipeline_status !== 'perdido')
            <form method="post" action="{{ route('tattoos.mark-lost', $tattoo) }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--danger" onclick="return confirm('Marcar este projeto como perdido?');">Marcar perdido</button>
            </form>
        @endif
    </div>
</div>
@endsection
