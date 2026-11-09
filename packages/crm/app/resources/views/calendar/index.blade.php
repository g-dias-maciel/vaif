@extends('layouts.app')

@section('title', 'Calendário')

@section('content')
@include('calendar.partials.styles')

@php
    $filters = request()->only(['type', 'owner_id', 'q']);
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Calendário</h1>
            <p class="meta" style="margin-top:6px;">Reuniões de vendas, estratégia e outros compromissos. Calls dos prospects aparecem automaticamente.</p>
        </div>
        <div class="cal-actions">
            <a href="{{ route('calendar.events.create') }}" class="btn btn--small">Novo compromisso</a>
            <a href="{{ route('team.index') }}" class="btn btn--ghost btn--small">Equipe</a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
        <div class="cal-month-nav">
            <a href="{{ route('calendar.index', array_merge(['month' => $prevMonth], $filters)) }}" class="btn btn--ghost btn--small">&larr;</a>
            <strong class="cal-month-title">{{ $monthLabel }}</strong>
            <a href="{{ route('calendar.index', array_merge(['month' => $nextMonth], $filters)) }}" class="btn btn--ghost btn--small">&rarr;</a>
            <a href="{{ route('calendar.index', $filters) }}" class="btn btn--ghost btn--small">Hoje</a>
        </div>
        <div class="cal-legend">
            <span><span class="cal-dot cal-dot--sales"></span>Vendas ({{ $counts['sales'] }})</span>
            <span><span class="cal-dot cal-dot--strategy"></span>Estratégia ({{ $counts['strategy'] }})</span>
            <span><span class="cal-dot cal-dot--other"></span>Outros ({{ $counts['other'] }})</span>
        </div>
    </div>

    <form method="get" action="{{ route('calendar.index') }}" class="cal-toolbar">
        <input type="month" name="month" value="{{ $month->format('Y-m') }}">
        <select name="type">
            <option value="">Todos os tipos</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="owner_id">
            <option value="">Todos os responsáveis</option>
            @foreach ($owners as $owner)
                <option value="{{ $owner->id }}" @selected((string) request('owner_id') === (string) $owner->id)>
                    {{ $owner->name }} — {{ $owner->roleLabel() }}
                </option>
            @endforeach
        </select>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar por título, local ou prospect">
        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('calendar.index') }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    <div class="cal-grid-wrap">
        <div class="cal-grid">
            @foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $dow)
                <div class="cal-dow">{{ $dow }}</div>
            @endforeach

            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    <div class="cal-day {{ $day['date']->month !== $month->month ? 'cal-day--muted' : '' }} {{ $day['date']->isToday() ? 'cal-day--today' : '' }}">
                        <div class="cal-day-head">
                            <span>{{ $day['date']->format('d') }}</span>
                            @if ($day['date']->day === 1)
                                <span>{{ $day['date']->format('M') }}</span>
                            @endif
                        </div>

                        @foreach ($day['events'] as $event)
                            <a href="{{ $event['url'] }}"
                               class="cal-event cal-event--{{ $event['type_tone'] }}"
                               title="{{ $event['title'] }} · {{ $event['start']->format('d/m H:i') }}@if ($event['owner']) · {{ $event['owner'] }}@endif">
                                <span class="cal-event-time">
                                    {{ $event['time_confirmed'] ? $event['start']->format('H:i') : 'A confirmar' }}
                                    @if ($event['kind'] === 'sales_call') · CRM @endif
                                </span>
                                <span class="cal-event-title">{{ $event['title'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    <h2 class="cal-list-title">Compromissos de {{ $monthLabel }}</h2>

    @if ($monthEvents->isEmpty())
        <p class="sales-empty">Nenhum compromisso neste mês com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Quando</th>
                    <th>Tipo</th>
                    <th>Compromisso</th>
                    <th>Responsável</th>
                    <th>Local / link</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($monthEvents as $event)
                    <tr>
                        <td>
                            <strong>{{ $event['start']->format('d/m') }}</strong>
                            <div class="sales-sub">
                                {{ $event['time_confirmed'] ? $event['start']->format('H:i') : 'Horário a confirmar' }}
                                @if ($event['end'] && $event['time_confirmed'])
                                    – {{ $event['end']->format('H:i') }}
                                @endif
                            </div>
                        </td>
                        <td><span class="cal-badge cal-badge--{{ $event['type_tone'] }}">{{ $event['type_label'] }}</span></td>
                        <td>
                            <strong>{{ $event['title'] }}</strong>
                            @if ($event['kind'] === 'sales_call')
                                <div class="sales-sub">Call registrada no prospect</div>
                            @elseif ($event['prospect'])
                                <div class="sales-sub">Prospect: {{ $event['prospect'] }}</div>
                            @endif
                        </td>
                        <td>{{ $event['owner'] ?? '—' }}</td>
                        <td>
                            @if ($event['meeting_url'])
                                <a href="{{ $event['meeting_url'] }}" target="_blank" rel="noopener noreferrer">Link da reunião</a>
                            @elseif ($event['location'])
                                {{ $event['location'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <div class="cal-actions">
                                @if ($event['editable'])
                                    <a href="{{ $event['url'] }}" class="btn btn--ghost btn--small">Editar</a>
                                @else
                                    <a href="{{ $event['prospect_url'] }}" class="btn btn--ghost btn--small">Abrir prospect</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
