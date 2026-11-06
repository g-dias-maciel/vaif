@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<h1 class="page-title">Dashboard — {{ $artist?->displayName() }}</h1>

<p class="meta" style="margin: -12px 0 18px;">
    Atualização automática · <span id="dash-updated">agora</span>
</p>

<div id="dash-status" class="panel" style="margin-bottom: 20px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
    @if ($artist && ($artist->status === 'live' || $artist->status === 'onboarding'))
        <div class="flash" style="display:inline-block; margin:0;">&#x25CF; Atendimento ativo</div>
        <form method="post" action="{{ route('settings.toggle') }}" class="inline">
            @csrf
            <button type="submit" class="btn btn--danger btn--small">Pausar agente</button>
        </form>
    @elseif ($artist && $artist->status === 'suspended')
        <div class="flash error" style="display:inline-block; margin:0;">&#x25CF; Atendimento pausado</div>
        <form method="post" action="{{ route('settings.toggle') }}" class="inline">
            @csrf
            <button type="submit" class="btn btn--small">Reativar agente</button>
        </form>
    @else
        <span class="meta">Atendimento: {{ $artist?->status ?: '—' }}</span>
    @endif

    @if ($activePromotion)
        <div class="promo-pill is-active">&#x25CF; Promoção ativa: {{ $activePromotion->name }}</div>
    @else
        <div class="promo-pill">Sem promoção ativa</div>
    @endif
</div>

<div class="dash-kpis">
    <a href="#dash-alerts" class="kpi {{ $needsAttention > 0 ? 'is-alert' : '' }}">
        <span class="kpi-value">{{ $needsAttention }}</span>
        <span class="kpi-label">precisam de você</span>
    </a>
    <div class="kpi">
        <span class="kpi-value">{{ $inConversation }}</span>
        <span class="kpi-label">em conversa</span>
    </div>
    <div class="kpi">
        <span class="kpi-value">{{ $scheduledCount }}</span>
        <span class="kpi-label">agendados</span>
    </div>
</div>

<div id="dash-alerts">
@if ($pendingHandoffs->isNotEmpty())
@php
    $handoffLabels = [
        'cover_up' => 'Cover-up',
        'reforma' => 'Reforma',
        'lead_requested_artist' => 'Pediu o artista',
        'below_piso' => 'Abaixo do piso',
        'vague' => 'Descrição vaga',
        'audio_2x' => 'Áudio repetido',
        'no_availability' => 'Sem horário disponível',
        'promotion_second' => 'Segunda tattoo da promoção 2 por 1',
    ];
    $handoffSummary = $pendingHandoffs
        ->groupBy(fn ($handoff) => $handoffLabels[$handoff->handoff_reason] ?? 'Atendimento')
        ->map(fn ($group, $label) => $label.' '.$group->count())
        ->take(3)
        ->implode(' · ');
    $handoffGroups = $pendingHandoffs
        ->groupBy(fn ($handoff) => $handoffLabels[$handoff->handoff_reason] ?? 'Atendimento')
        ->sortKeys();
@endphp
<details class="panel accordion" data-accordion="handoffs" data-persist="handoffs" style="margin-bottom: 20px; border-color: var(--danger);">
    <summary>
        <h2 class="section-title">Atendimentos aguardando você</h2>
        <span class="tag">{{ $pendingHandoffs->count() }}</span>
        <span class="accordion-hint">{{ $handoffSummary }}</span>
    </summary>
    <div class="accordion-body">
        <p class="meta">O agente passou estes clientes para você. Assuma para continuar no CRM, ou responda pelo WhatsApp — a conversa sai desta lista sozinha.</p>

        @foreach ($handoffGroups as $groupLabel => $group)
            <div class="handoff-group">
                <h3 class="handoff-group-title">
                    {{ $groupLabel }}
                    <span class="tag">{{ $group->count() }}</span>
                </h3>
                <ul class="slot-list">
                    @foreach ($group as $handoff)
                        @php
                            $waitingSince = $handoff->handoff_at ?? $handoff->updated_at;
                            $waitingMin = $waitingSince ? (int) $waitingSince->diffInMinutes(now()) : null;
                            $waitingLabel = $waitingMin === null
                                ? null
                                : ($waitingMin < 60
                                    ? $waitingMin.' min'
                                    : ($waitingMin < 1440
                                        ? intdiv($waitingMin, 60).' h'
                                        : intdiv($waitingMin, 1440).' d'));
                        @endphp
                        <li>
                            <div class="slot-main">
                                <strong>
                                    @if ($handoff->lead)
                                        <a href="{{ route('leads.show', $handoff->lead) }}">{{ $handoff->lead->displayName() }}</a>
                                    @else
                                        Cliente
                                    @endif
                                </strong>
                                <span>
                                    {{ $handoffLabels[$handoff->handoff_reason] ?? ($handoff->handoff_reason ?: 'Atendimento solicitado') }}
                                    @if ($handoff->lead?->phone_e164)
                                        · {{ $handoff->lead->phone_e164 }}
                                    @endif
                                </span>
                                @if ($waitingLabel)
                                    <span class="meta">Aguardando há {{ $waitingLabel }}</span>
                                @endif
                            </div>
                            <div class="actions-row">
                                @if ($handoff->lead)
                                    <a href="{{ route('leads.show', $handoff->lead) }}" class="btn btn--ghost btn--small">Ver conversa</a>
                                    @if ($handoff->lead->whatsapp_url)
                                        <a href="{{ $handoff->lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
                                    @endif
                                @endif
                                <form method="post" action="{{ route('projects.handoff.accept', $handoff) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn--small">Assumir</button>
                                </form>
                                <form method="post" action="{{ route('projects.handoff.return', $handoff) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn--ghost btn--small">Devolver à Beatriz</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</details>
@endif

@if (isset($pendingContactGates) && $pendingContactGates->isNotEmpty())
<div id="pending-contact-gates" class="panel" style="margin-bottom: 20px; border-color: var(--gold);">
    <h2 class="section-title" style="margin-top:0;">
        Contatos salvos aguardando você
        <span class="tag">{{ $pendingContactGates->count() }}</span>
    </h2>
    <p class="meta">Estes números estão na sua lista de contatos do WhatsApp. A Beatriz não respondeu. Libere para ela atender, ou bloqueie.</p>

    <ul class="slot-list" style="margin-top: 14px;">
        @foreach ($pendingContactGates as $gateLead)
            <li>
                <div class="slot-main">
                    <strong>
                        <a href="{{ route('leads.show', $gateLead) }}">{{ $gateLead->displayName() }}</a>
                    </strong>
                    <span>
                        @if ($gateLead->phone_e164)
                            {{ $gateLead->phone_e164 }}
                        @endif
                        @if ($gateLead->name)
                            · {{ $gateLead->name }}
                        @endif
                    </span>
                    <span class="meta">Primeira mensagem {{ $gateLead->updated_at?->format('d/m H:i') }}</span>
                </div>
                <div class="actions-row" style="display:flex; gap:8px;">
                    <form method="post" action="{{ route('leads.contact-gate', $gateLead) }}" class="inline">
                        @csrf
                        <input type="hidden" name="decision" value="allow">
                        <button type="submit" class="btn btn--small">Responder</button>
                    </form>
                    <form method="post" action="{{ route('leads.contact-gate', $gateLead) }}" class="inline"
                        onsubmit="return confirm('Bloquear este contato? A Beatriz não responderá mais.');">
                        @csrf
                        <input type="hidden" name="decision" value="block">
                        <button type="submit" class="btn btn--ghost btn--small">Não responder</button>
                    </form>
                </div>
            </li>
        @endforeach
    </ul>
</div>
@endif

@if ($pendingDeposits->isNotEmpty())
<div id="pending-deposits" class="panel" style="margin-bottom: 20px; border-color: var(--gold);">
    <h2 class="section-title" style="margin-top:0;">
        Sinais aguardando confirmação
        <span class="tag">{{ $pendingDeposits->count() }}</span>
    </h2>
    <p class="meta">Confira o PIX recebido e confirme o sinal para liberar o agendamento.</p>

    <ul class="slot-list" style="margin-top: 14px;">
        @foreach ($pendingDeposits as $depositProject)
            <li>
                <div class="slot-main">
                    <strong>
                        @if ($depositProject->lead)
                            <a href="{{ route('leads.show', $depositProject->lead) }}">{{ $depositProject->lead->displayName() }}</a>
                        @else
                            Cliente
                        @endif
                    </strong>
                    <span>
                        Sinal de R$ {{ number_format(($depositProject->deposit?->amount_cents ?? 0) / 100, 2, ',', '.') }}
                        @if ($depositProject->booked_at)
                            · {{ $depositProject->booked_at->format('d/m/Y H:i') }}
                        @endif
                    </span>
                </div>
                <form method="post" action="{{ route('projects.confirm-deposit', $depositProject) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn--small">Confirmar sinal</button>
                </form>
            </li>
        @endforeach
    </ul>
</div>
@endif
</div>

<div id="dash-revenue" class="panel" style="margin-bottom: 20px;">
    <h2 class="section-title" style="margin-top:0;">Faturamento — {{ $periodLabel }}</h2>
    <div class="stat-grid stat-grid--revenue">
        <div class="stat-card">
            <div class="stat-value">R$ {{ number_format($realizadoCents / 100, 2, ',', '.') }}</div>
            <div class="stat-label">Realizado</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">R$ {{ number_format($agendadoCents / 100, 2, ',', '.') }}</div>
            <div class="stat-label">Agendado</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">R$ {{ number_format($esperadoCents / 100, 2, ',', '.') }}</div>
            <div class="stat-label">Esperado (flutuante)</div>
        </div>
    </div>
</div>

<div id="dash-agenda">
<h2 class="section-title" style="margin-top: 8px;">Agenda da semana</h2>

<div class="week-nav">
    <a href="{{ route('dashboard', ['week' => $prevWeek, 'period' => $period]) }}" class="btn btn--ghost btn--small">&larr;</a>
    <span class="week-range">{{ $weekRange }}</span>
    <a href="{{ route('dashboard', ['week' => $nextWeek, 'period' => $period]) }}" class="btn btn--ghost btn--small">&rarr;</a>
    <span class="meta">{{ $weekCount }} agendamento(s) na semana</span>
</div>

<div class="week-scroll">
    <div class="week-grid">
        @foreach ($days as $day)
            <div class="week-day {{ $day['isToday'] ? 'today' : '' }}">
                <div class="week-day-head">
                    {{ $day['label'] }}
                    <span class="date">{{ $day['date']->format('d/m') }}</span>
                </div>

                @forelse ($day['slots'] as $slot)
                    @if ($slot->kind === 'booking')
                        <a class="week-slot" href="{{ $slot->lead ? route('leads.show', $slot->lead) : '#' }}">
                            <div class="time">{{ $slot->start_at->format('H:i') }}–{{ $slot->end_at->format('H:i') }}</div>
                            <div class="name">{{ $slot->lead?->displayName() ?? 'Cliente' }}</div>
                            @if ($slot->project?->placement)
                                <div class="place">{{ $slot->project->placement_label }}</div>
                            @endif
                        </a>
                    @else
                        <div class="week-slot blocked">Indisponível · {{ $slot->start_at->format('H:i') }}</div>
                    @endif
                @empty
                    <div class="week-empty">—</div>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
</div>

<div class="pipeline-head">
    <h2 class="section-title" style="margin: 0;">Pipeline</h2>
    <input type="search" id="board-search" class="board-search" placeholder="Buscar por nome ou telefone" autocomplete="off">
    <form method="get" action="{{ route('dashboard') }}" class="period-form" style="margin: 0;">
        <label for="period">Período</label>
        <select name="period" id="period" onchange="this.form.submit()">
            @foreach ($periodOptions as $key => $label)
                <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
            @endforeach
        </select>
        @if (request()->query('week'))
            <input type="hidden" name="week" value="{{ request()->query('week') }}">
        @endif
    </form>
</div>

<div id="dash-pipeline">
    @foreach ($lanes as $laneKey => $lane)
        @php($laneEmpty = ($laneCounts[$laneKey] ?? 0) === 0)
        <details class="pipeline-lane" data-persist="lane-{{ $laneKey }}" @if ($lane['open']) open @endif>
            <summary>
                {{ $lane['label'] }}
                <span class="tag">{{ $laneCounts[$laneKey] ?? 0 }}</span>
            </summary>
            <div class="lane-body">
                @if ($laneEmpty)
                    <p class="meta">Nenhum cliente aqui.</p>
                @else
                    @include('partials.project-board', [
                        'projects' => $projects,
                        'pipeline' => $lane['pipeline'],
                        'readonly' => false,
                        'agentAction' => true,
                        'cardLimit' => $laneKey === 'live' ? 6 : 4,
                    ])
                @endif
            </div>
        </details>
    @endforeach
</div>

@push('scripts')
<script>
(function () {
    const sections = ['dash-status', 'dash-alerts', 'dash-revenue', 'dash-agenda', 'dash-pipeline'];
    const stamp = document.getElementById('dash-updated');
    const search = document.getElementById('board-search');

    function applyBoardFilter() {
        if (!search) return;

        const query = search.value.trim().toLowerCase();
        const cards = document.querySelectorAll('#dash-pipeline .card');

        cards.forEach(function (card) {
            card.style.display = (!query || card.textContent.toLowerCase().includes(query)) ? '' : 'none';
        });

        // Matching a card hidden behind "Ver todos" is useless unless the
        // expander opens. Do not close it again when the query clears, so the
        // artist keeps whatever they opened.
        if (query) {
            document.querySelectorAll('#dash-pipeline details.cards-more').forEach(function (details) {
                details.open = true;
            });
        }
    }

    if (search) {
        search.addEventListener('input', applyBoardFilter);
    }

    async function refresh() {
        if (document.hidden) return;
        const active = document.activeElement;
        if (active && active.closest && active.closest('form')) return;

        const url = new URL(window.location.href);
        url.searchParams.set('_live', Date.now());

        try {
            const res = await fetch(url.toString(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) return;

            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');

            // The alerts and pipeline sections are replaced wholesale, so
            // remember which disclosures the artist opened or the lists would
            // collapse under them every 15 seconds.
            const openDetails = Array.from(
                document.querySelectorAll('#dash-alerts details[data-persist][open], #dash-pipeline details[data-persist][open]')
            ).map(function (el) { return el.dataset.persist; });

            sections.forEach(function (id) {
                const next = doc.getElementById(id);
                const current = document.getElementById(id);
                if (next && current) current.innerHTML = next.innerHTML;
            });

            openDetails.forEach(function (key) {
                document.querySelectorAll('details[data-persist="' + key + '"]').forEach(function (el) {
                    el.open = true;
                });
            });

            applyBoardFilter();

            if (stamp) {
                stamp.textContent = new Date().toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit',
                });
            }
        } catch (e) {
            // A transient network error must not break the dashboard.
        }
    }

    setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh();
    });
})();
</script>
@endpush
@endsection
