@extends('layouts.app')

@section('title', $lead->nome ?: 'Cliente')

@php
    $tipo = $lead->tipo_tatuagem;
    $coverUpLabel = $tipo === 'cobertura'
        ? 'Sim (cobertura)'
        : ($tipo === 'nova' ? 'Não (tatuagem nova)' : ($tipo ?: '—'));
    $promoQuote = $lead->quoteFor($lead->placement, $lead->body_zone);
@endphp

@section('content')
<a href="{{ route('dashboard') }}" class="back">&larr; Voltar</a>
<h1 class="page-title">
    {{ $lead->nome ?: 'Sem nome' }}
    @if ($lead->is_returning) <span class="tag">Recorrente</span> @endif
</h1>

<div class="panel">
    <div class="stat-grid" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="stat-label">Status</div>
            <div class="stat-value" style="font-size: 1.2rem;">{{ $pipeline[$lead->pipeline_status] ?? ($lead->pipeline_status ?: '—') }}</div>
        </div>
        @if ($lead->charged_price_cents !== null)
            <div class="stat-card">
                <div class="stat-label">Preço</div>
                <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($lead->charged_price_cents / 100, 2, ',', '.') }}</div>
            </div>
        @endif
        @if ($lead->deposit_amount)
            <div class="stat-card">
                <div class="stat-label">Sinal</div>
                <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($lead->deposit_amount / 100, 2, ',', '.') }}</div>
            </div>
        @endif
        @if ($lead->booked_date)
            <div class="stat-card">
                <div class="stat-label">Agendado</div>
                <div class="stat-value" style="font-size: 1.2rem;">{{ $lead->booked_date->format('d/m/Y H:i') }}</div>
            </div>
        @endif
    </div>

    <h2 class="section-title" style="margin-top: 0;">Detalhes do projeto</h2>
    <div class="detail-grid">
        <div><span class="meta">Primeira tatuagem</span><div>{{ $lead->primeira_tatuagem === null ? '—' : ($lead->primeira_tatuagem ? 'Sim' : 'Não') }}</div></div>
        <div><span class="meta">Cobertura</span><div>{{ $coverUpLabel }}</div></div>
        <div><span class="meta">Local</span><div>{{ $lead->placement ?: '—' }}</div></div>
        <div><span class="meta">Tamanho / zona</span><div>{{ $lead->body_zone ?: '—' }}</div></div>
        <div><span class="meta">Estilo</span><div>{{ $lead->style ?: '—' }}</div></div>
        <div><span class="meta">Telefone</span><div>{{ $lead->telefone }}</div></div>
        <div><span class="meta">Origem</span><div>{{ $lead->lead_source ?: '—' }}</div></div>
        <div><span class="meta">Depósito</span><div>{{ $lead->deposit_status ?: '—' }}</div></div>
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

    @if ($lead->significado)
        <div style="margin-top: 16px;">
            <span class="meta">Significado</span>
            <p style="margin-top: 4px;">{{ $lead->significado }}</p>
        </div>
    @endif

    @if ($lead->reference_pics)
        <h2 class="section-title">Fotos de referência</h2>
        <div class="ref-grid">
            @foreach ($lead->reference_pics as $img)
                <a href="{{ $img }}" target="_blank" rel="noopener"><img src="{{ $img }}" alt="Referência"></a>
            @endforeach
        </div>
    @endif

    <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        @if ($lead->whatsapp_url)
            <a href="{{ $lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp">WhatsApp</a>
        @endif
        @if ($lead->pipeline_status !== 'perdido')
            <form method="post" action="{{ route('leads.mark-lost', $lead) }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--danger" onclick="return confirm('Marcar este projeto como perdido?');">Marcar perdido</button>
            </form>
        @endif
    </div>
</div>

@if ($lead->promotionQuotes->isNotEmpty())
<div class="panel" style="margin-bottom: 20px;">
    <h2 class="section-title" style="margin-top:0;">Cotações de promoção</h2>
    @foreach ($lead->promotionQuotes->sortByDesc('quoted_at') as $quote)
        <div class="promo-row {{ $quote->isOpen() ? 'is-active' : '' }}">
            <div class="promo-main">
                <strong>{{ $quote->promotion?->name ?: 'Promoção' }}</strong>
                <span class="meta">
                    {{ $quote->placement }} · {{ $quote->body_zone }}
                    · 1ª {{ $quote->firstPriceLabel() }}
                    · 2ª até {{ $quote->secondCapLabel() }}
                </span>
                @if ($quote->valid_until)
                    <span class="meta">
                        Válida até {{ $quote->valid_until->format('d/m/Y') }}
                        @if ($quote->daysRemaining() !== null) · {{ $quote->daysRemaining() }} dia(s) restante(s) @endif
                    </span>
                @endif
            </div>
            <span class="tag {{ $quote->isOpen() ? 'tag-success' : 'tag-muted' }}">{{ $quote->statusLabel() }}</span>
        </div>
    @endforeach
    <p class="meta" style="margin-top: 10px;">Cotações de promoção enviadas pelo agente. O preço fica protegido até a validade.</p>
</div>
@endif

<div class="panel" style="margin-bottom: 20px;">
    <h2 class="section-title" style="margin-top:0;">Agendamentos e eventos</h2>
    <ul class="slot-list">
        @forelse ($lead->calendar->sortBy('start_at') as $slot)
            <li>
                <div class="slot-main">
                    <strong>{{ $slot->start_at->format('d/m/Y H:i') }} – {{ $slot->end_at->format('H:i') }}</strong>
                    <span class="meta">{{ $slot->type === 'blocked' ? 'Bloqueado' : 'Agendado' }}</span>
                </div>
            </li>
        @empty
            <li class="empty">Nenhum agendamento.</li>
        @endforelse
        @foreach ($lead->events->sortByDesc('created_at') as $event)
            <li>
                <div class="slot-main">
                    <strong>{{ $event->event_type }}</strong>
                    <span class="meta">{{ $event->created_at?->format('d/m/Y H:i') }}</span>
                </div>
            </li>
        @endforeach
    </ul>
</div>

<div class="panel">
    <h2 class="section-title" style="margin-top:0;">Notas</h2>
    <div class="notes" style="border:none; padding:0;">
        @forelse ($lead->notes as $note)
            <div class="note">{{ $note->body }} <span class="note-by">— {{ $note->author?->name }}</span></div>
        @empty
            <p class="meta">Nenhuma nota.</p>
        @endforelse
    </div>
    <form method="post" action="{{ route('leads.notes.store', $lead) }}" class="note-form" style="margin-top: 14px;">
        @csrf
        <input type="text" name="body" placeholder="Adicionar nota…" required>
        <button type="submit" class="btn btn--ghost btn--small">Nota</button>
    </form>
</div>
@endsection
