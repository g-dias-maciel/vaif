@extends('layouts.app')

@section('title', $lead->nome ?: 'Cliente')

@section('content')
<a href="{{ route('dashboard') }}" class="back">&larr; Voltar</a>
<h1 class="page-title">{{ $lead->nome ?: 'Sem nome' }} @if ($lead->is_returning) <span class="tag">Recorrente</span> @endif</h1>

<div class="panel" style="margin-bottom: 20px;">
    <div class="detail-grid">
        <div><span class="meta">Telefone</span><div>{{ $lead->telefone }}</div></div>
        <div><span class="meta">Origem</span><div>{{ $lead->lead_source ?: '—' }}</div></div>
    </div>
    <div style="margin-top: 16px;">
        @if ($lead->whatsapp_url)
            <a href="{{ $lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
        @endif
    </div>
</div>

@if ($lead->promotionQuotes->isNotEmpty())
<div class="panel" style="margin-bottom: 20px;">
    <h2 class="section-title" style="margin-top:0;">Promoções</h2>
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
    <h2 class="section-title" style="margin-top:0;">Tatuagens ({{ $lead->tattoos->count() }})</h2>    <ul class="slot-list">
        @forelse ($lead->tattoos as $tattoo)
            <li>
                <div class="slot-main">
                    <a href="{{ route('tattoos.show', $tattoo) }}">
                        <strong>{{ collect([$tattoo->placement, $tattoo->body_zone, $tattoo->style])->filter()->implode(' · ') ?: 'Tatuagem' }}</strong>
                    </a>
                    <span class="meta">
                        {{ $pipeline[$tattoo->pipeline_status] ?? $tattoo->pipeline_status }}
                        @if ($tattoo->charged_price_cents !== null) · R$ {{ number_format($tattoo->charged_price_cents / 100, 2, ',', '.') }} @endif
                        @if ($tattoo->booked_date) · {{ $tattoo->booked_date->format('d/m/Y H:i') }} @endif
                    </span>
                </div>
                @if ($tattoo->pipeline_status !== 'perdido')
                    <form method="post" action="{{ route('tattoos.mark-lost', $tattoo) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn--danger btn--small" onclick="return confirm('Marcar este projeto como perdido?');">Marcar perdido</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="empty">Nenhuma tatuagem registrada.</li>
        @endforelse
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
