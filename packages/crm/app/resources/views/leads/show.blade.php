@extends('layouts.app')

@section('title', $lead->displayName())

@section('content')
<a href="{{ route('dashboard') }}" class="back">&larr; Voltar</a>
<h1 class="page-title">
    {{ $lead->displayName() }}
    @if ($lead->is_returning) <span class="tag">Recorrente</span> @endif
</h1>

<div class="panel">
    <div class="detail-grid">
        <div><span class="meta">Telefone</span><div>{{ $lead->phone_e164 }}</div></div>
        <div><span class="meta">Origem</span><div>{{ $lead->source ?: '—' }}</div></div>
        <div><span class="meta">Primeiro contato</span><div>{{ $lead->first_seen_at?->format('d/m/Y H:i') ?: '—' }}</div></div>
        <div><span class="meta">Última mensagem</span><div>{{ $lead->last_message_at?->format('d/m/Y H:i') ?: '—' }}</div></div>
    </div>

    @if ($lead->whatsapp_url)
        <div style="margin-top: 16px;">
            <a href="{{ $lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp">WhatsApp</a>
        </div>
    @endif
</div>

@forelse ($lead->projects->sortByDesc('created_at') as $project)
    @php
        $promoQuote = $project->quotes
            ->where('kind', 'promotion')
            ->sortByDesc('created_at')
            ->first();
    @endphp

    <div class="panel" style="margin-bottom: 20px;">
        <h2 class="section-title" style="margin-top: 0;">
            Projeto
            <span class="tag">{{ $project->stage_label }}</span>
        </h2>

        <div class="stat-grid" style="margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-label">Status</div>
                <div class="stat-value" style="font-size: 1.2rem;">{{ $pipeline[$project->stage_key] ?? ($project->stage_key ?: '—') }}</div>
            </div>
            @if ($project->charged_price_cents !== null)
                <div class="stat-card">
                    <div class="stat-label">Preço</div>
                    <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($project->charged_price_cents / 100, 2, ',', '.') }}</div>
                </div>
            @endif
            @if ($project->deposit)
                <div class="stat-card">
                    <div class="stat-label">Sinal</div>
                    <div class="stat-value" style="font-size: 1.2rem;">R$ {{ number_format($project->deposit->amount_cents / 100, 2, ',', '.') }}</div>
                </div>
            @endif
            @if ($project->booked_at)
                <div class="stat-card">
                    <div class="stat-label">Agendado</div>
                    <div class="stat-value" style="font-size: 1.2rem;">{{ $project->booked_at->format('d/m/Y H:i') }}</div>
                </div>
            @endif
        </div>

        <h2 class="section-title" style="margin-top: 0;">Detalhes do projeto</h2>
        <div class="detail-grid">
            <div><span class="meta">Primeira tatuagem</span><div>{{ $project->first_tattoo === null ? '—' : ($project->first_tattoo ? 'Sim' : 'Não') }}</div></div>
            <div><span class="meta">Cobertura</span><div>{{ $project->tattoo_type_label }}</div></div>
            <div><span class="meta">Local</span><div>{{ $project->placement ?: '—' }}</div></div>
            <div><span class="meta">Tamanho / zona</span><div>{{ $project->body_zone ?: '—' }}</div></div>
            <div><span class="meta">Estilo</span><div>{{ $project->style ?: '—' }}</div></div>
            <div><span class="meta">Depósito</span><div>{{ $project->deposit_status ?: '—' }}</div></div>
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

        @if ($project->meaning)
            <div style="margin-top: 16px;">
                <span class="meta">Significado</span>
                <p style="margin-top: 4px;">{{ $project->meaning }}</p>
            </div>
        @endif

        @if ($project->reference_pics)
            <h2 class="section-title">Fotos de referência</h2>
            <div class="ref-grid">
                @foreach ($project->reference_pics as $img)
                    <a href="{{ $img }}" target="_blank" rel="noopener"><img src="{{ $img }}" alt="Referência"></a>
                @endforeach
            </div>
        @endif

        @if ($project->stage_key !== 'perdido')
            <div style="margin-top: 20px;">
                <form method="post" action="{{ route('projects.mark-lost', $project) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn--danger" onclick="return confirm('Marcar este projeto como perdido?');">Marcar perdido</button>
                </form>
            </div>
        @endif

        @if ($project->quotes->isNotEmpty())
            <h2 class="section-title">Cotações</h2>
            @foreach ($project->quotes->sortByDesc('created_at') as $quote)
                <div class="promo-row {{ $quote->isOpen() ? 'is-active' : '' }}">
                    <div class="promo-main">
                        <strong>{{ $quote->promotion?->name ?: 'Tabela' }}</strong>
                        <span class="meta">
                            {{ $project->placement ?: '—' }} · {{ $project->body_zone ?: '—' }}
                            · 1ª {{ $quote->firstPriceLabel() }}
                            @if ($quote->second_cap_cents !== null)
                                · 2ª até {{ $quote->secondCapLabel() }}
                            @endif
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
        @endif

        <h2 class="section-title">Agendamentos</h2>
        <ul class="slot-list">
            @forelse ($project->calendar->where('kind', 'booking')->sortBy('start_at') as $slot)
                <li>
                    <div class="slot-main">
                        <strong>{{ $slot->start_at->format('d/m/Y H:i') }} – {{ $slot->end_at->format('H:i') }}</strong>
                        <span class="meta">{{ $slot->active ? 'Agendado' : 'Cancelado' }}</span>
                    </div>
                </li>
            @empty
                <li class="empty">Nenhum agendamento.</li>
            @endforelse
        </ul>
    </div>
@empty
    <div class="panel">
        <p class="meta">Nenhum projeto registrado para este cliente.</p>
    </div>
@endforelse

<div class="panel" style="margin-bottom: 20px;">
    <h2 class="section-title" style="margin-top:0;">Histórico</h2>
    <ul class="slot-list">
        @forelse ($lead->events->sortByDesc('created_at') as $event)
            <li>
                <div class="slot-main">
                    <strong>{{ $event->type }}</strong>
                    <span class="meta">{{ $event->actor }} · {{ $event->created_at?->format('d/m/Y H:i') }}</span>
                </div>
            </li>
        @empty
            <li class="empty">Nenhum evento.</li>
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
