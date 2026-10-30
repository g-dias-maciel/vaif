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

<div id="dash-alerts">
@if ($pendingHandoffs->isNotEmpty())
<div class="panel" style="margin-bottom: 20px; border-color: var(--danger);">
    <h2 class="section-title" style="margin-top:0;">
        Atendimentos aguardando você
        <span class="tag">{{ $pendingHandoffs->count() }}</span>
    </h2>
    <p class="meta">O agente passou estes clientes para você. Abra a conversa ou responda pelo WhatsApp para assumir o atendimento.</p>

    @php
        $handoffLabels = [
            'cover_up' => 'Cover-up',
            'reforma' => 'Reforma',
            'lead_requested_artist' => 'Pediu o artista',
            'below_piso' => 'Abaixo do piso',
            'vague' => 'Descrição vaga',
            'audio_2x' => 'Áudio repetido',
            'no_availability' => 'Sem horário disponível',
        ];
    @endphp

    <ul class="slot-list" style="margin-top: 14px;">
        @foreach ($pendingHandoffs as $handoff)
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
                    <span class="meta">Atualizado {{ $handoff->updated_at?->format('d/m H:i') }}</span>
                </div>
                <div class="actions-row">
                    @if ($handoff->lead)
                        <a href="{{ route('leads.show', $handoff->lead) }}" class="btn btn--ghost btn--small">Ver conversa</a>
                        @if ($handoff->lead->whatsapp_url)
                            <a href="{{ $handoff->lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
                        @endif
                    @endif
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

<div id="dash-pipeline">
<div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 28px 0 14px;">
    <h2 class="section-title" style="margin: 0;">Pipeline</h2>
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
@include('partials.project-board', ['projects' => $projects, 'pipeline' => $pipeline, 'readonly' => false, 'agentAction' => true])
</div>

@push('scripts')
<script>
(function () {
    const sections = ['dash-status', 'dash-alerts', 'dash-revenue', 'dash-agenda', 'dash-pipeline'];
    const stamp = document.getElementById('dash-updated');

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
            sections.forEach(function (id) {
                const next = doc.getElementById(id);
                const current = document.getElementById(id);
                if (next && current) current.innerHTML = next.innerHTML;
            });

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
