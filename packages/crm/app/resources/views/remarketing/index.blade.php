@extends('layouts.app')

@section('title', 'Remarketing')

@push('styles')
@include('sales.partials.styles')
<style>
    .remarketing-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .remarketing-tab {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 8px 13px; border: 1px solid var(--border); border-radius: 999px;
        color: var(--muted); font-size: .76rem; text-decoration: none;
    }
    .remarketing-tab:hover { color: var(--ink); border-color: var(--gold); }
    .remarketing-tab.is-active { color: var(--ink); border-color: var(--gold); background: var(--panel-2); }
    .remarketing-tab .count {
        min-width: 20px; padding: 1px 6px; border-radius: 999px;
        background: var(--bg); border: 1px solid var(--border);
        font-size: .66rem; text-align: center;
    }
    .remarketing-summary { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; color: var(--muted); font-size: .76rem; }
    .rank { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .score-badge {
        display: inline-block; padding: 4px 9px; border-radius: 999px; border: 1px solid var(--border);
        font-size: .68rem; letter-spacing: .4px; text-transform: uppercase; white-space: nowrap;
    }
    .score-badge--hot { border-color: #4ade80; color: #4ade80; }
    .score-badge--warm { border-color: var(--gold); color: var(--gold); }
    .score-badge--cold { border-color: var(--muted); color: var(--muted); }
    .score-badge--won { border-color: #4ade80; color: #4ade80; }
    .score-badge--closed { border-color: var(--muted); color: var(--muted); opacity: .65; }
    .score-reasons { display: flex; flex-direction: column; gap: 2px; margin-top: 6px; font-size: .7rem; color: var(--muted); }
    .score-reason--pos { color: #4ade80; }
    .score-reason--neg { color: #d05a5a; }
    .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .remarketing-pagination { display: flex; gap: 14px; justify-content: center; align-items: center; margin-top: 18px; font-size: .8rem; }
    .remarketing-pagination a { color: var(--gold); }
    .remarketing-empty { padding: 28px 4px; text-align: center; }
    .remarketing-empty strong { display: block; margin-bottom: 6px; }
    @media (max-width: 760px) {
        .remarketing-table thead { display: none; }
        .remarketing-table, .remarketing-table tbody, .remarketing-table tr, .remarketing-table td { display: block; width: 100%; }
        .remarketing-table tr { border-bottom: 1px solid var(--border); padding: 12px 0; }
        .remarketing-table td { border: 0; padding: 4px 0; }
    }
</style>
@endpush

@section('content')
@php
    $queryFor = function (array $overrides = []): array {
        $query = array_merge(request()->query(), $overrides);
        unset($query['page']);

        if (isset($overrides['page'])) {
            $query['page'] = $overrides['page'];
        }

        return array_filter($query, fn ($value) => $value !== null && $value !== '');
    };

    $segment = $filters['segment'];
    $counts = $result['counts'];
    $segments = [
        'retomar' => 'Retomar',
        'abertos' => 'Todos abertos',
        'reviver' => 'Reviver',
    ];
@endphp

<h1 class="page-title">Remarketing — {{ $artist->displayName() }}</h1>
<p class="meta" style="margin-top:-8px; margin-bottom:18px;">
    Priorize quem tem mais chance de fechar. A nota considera etapa, orçamento, sinal e o tempo desde o último contato.
</p>

<div class="remarketing-tabs">
    @foreach ($segments as $key => $label)
        <a href="{{ route('remarketing.index', $queryFor(['segment' => $key])) }}"
           class="remarketing-tab {{ $segment === $key ? 'is-active' : '' }}">
            {{ $label }}
            <span class="count">{{ $counts['segments'][$key] ?? 0 }}</span>
        </a>
    @endforeach
</div>

<div class="panel">
    <form method="get" action="{{ route('remarketing.index') }}" class="sales-toolbar">
        <input type="hidden" name="segment" value="{{ $segment }}">

        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Buscar por nome ou telefone" autocomplete="off">

        <select name="tier" aria-label="Filtrar por temperatura">
            <option value="">Todas as temperaturas</option>
            <option value="hot" @selected($filters['tier'] === 'hot')>Quente</option>
            <option value="warm" @selected($filters['tier'] === 'warm')>Morno</option>
            <option value="cold" @selected($filters['tier'] === 'cold')>Frio</option>
        </select>

        @if ($segment === 'retomar')
            <select name="days" aria-label="Filtrar por tempo sem contato">
                @foreach ($allowedDays as $days)
                    <option value="{{ $days }}" @selected($filters['days'] === $days)>Sem contato há {{ $days }}+ dias</option>
                @endforeach
            </select>
        @endif

        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('remarketing.index', ['segment' => $segment]) }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    <div class="remarketing-summary">
        <span>{{ $result['total'] }} {{ $result['total'] === 1 ? 'lead nesta fila' : 'leads nesta fila' }}</span>
        <span>{{ $counts['tiers']['hot'] ?? 0 }} quentes</span>
        <span>{{ $counts['tiers']['warm'] ?? 0 }} mornos</span>
        <span>{{ $counts['tiers']['cold'] ?? 0 }} frios</span>
    </div>

    @if (empty($result['items']))
        <div class="remarketing-empty">
            @if ($segment === 'retomar')
                <strong>Nenhum lead parado há {{ $filters['days'] }}+ dias.</strong>
                <span class="meta">Bom sinal. Veja “Todos abertos” para acompanhar a fila inteira.</span>
            @elseif ($segment === 'reviver')
                <strong>Nenhum lead perdido com histórico para reviver.</strong>
                <span class="meta">Leads que chegaram a orçamento, sinal ou agendamento aparecem aqui.</span>
            @else
                <strong>Nenhum lead aberto encontrado.</strong>
                <span class="meta">Ajuste os filtros ou aguarde novos contatos.</span>
            @endif
        </div>
    @else
        <table class="sales-table remarketing-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Etapa</th>
                    <th>Chance</th>
                    <th>Última atividade</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result['items'] as $index => $row)
                    @php
                        $lead = $row['lead'];
                        $project = $row['project'];
                        $score = $row['score'];
                        $rank = ($result['page'] - 1) * $result['page_size'] + $index + 1;
                    @endphp
                    <tr>
                        <td class="rank">{{ $rank }}</td>
                        <td>
                            <a href="{{ route('leads.show', $lead) }}"><strong>{{ $lead->displayName() }}</strong></a>
                            <div class="sales-sub">
                                {{ $lead->phone_e164 }}
                                @if ($row['profile']->returning)
                                    · <span class="tag">Recorrente</span>
                                @endif
                                @if ($lead->agent_blocked)
                                    · <span class="tag tag-muted">Agente silenciado</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="tag">{{ $project?->stage_label ?? '—' }}</span>
                            @if ($project?->charged_price_cents !== null)
                                <div class="sales-sub">
                                    R$ {{ number_format($project->charged_price_cents / 100, 2, ',', '.') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="score-badge score-badge--{{ $score->tier }}">
                                {{ $score->score }}/100 · {{ $score->tierLabel() }}
                            </span>
                            <div class="score-reasons">
                                @foreach ($score->topReasons(4) as $reason)
                                    <span class="{{ $reason['points'] > 0 ? 'score-reason--pos' : 'score-reason--neg' }}">
                                        {{ $reason['points'] > 0 ? '+' : '−' }}{{ abs($reason['points']) }} {{ $reason['label'] }}
                                    </span>
                                @endforeach
                            </div>
                            @if ($score->nextAction)
                                <div class="sales-sub" style="margin-top:6px;">Próximo passo: {{ $score->nextAction }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $row['last_activity']?->diffForHumans() ?? '—' }}
                            <div class="sales-sub">{{ $row['last_activity']?->format('d/m/Y') ?? '' }}</div>
                            @if (! empty($row['touch']))
                                <div class="sales-sub">Última tentativa: {{ $row['touch']['at']->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post" action="{{ route('remarketing.contact', $lead) }}" target="_blank" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="whatsapp">
                                    <input type="hidden" name="segment" value="{{ $segment }}">
                                    @if ($project)
                                        <input type="hidden" name="project_id" value="{{ $project->id }}">
                                    @endif
                                    <button type="submit" class="btn btn--small">WhatsApp</button>
                                </form>

                                <form method="post" action="{{ route('remarketing.contact', $lead) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="contacted">
                                    <input type="hidden" name="segment" value="{{ $segment }}">
                                    @if ($project)
                                        <input type="hidden" name="project_id" value="{{ $project->id }}">
                                    @endif
                                    <button type="submit" class="btn btn--ghost btn--small">Já falei</button>
                                </form>

                                <form method="post" action="{{ route('remarketing.contact', $lead) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="dismissed">
                                    <input type="hidden" name="segment" value="{{ $segment }}">
                                    @if ($project)
                                        <input type="hidden" name="project_id" value="{{ $project->id }}">
                                    @endif
                                    <button type="submit" class="btn btn--ghost btn--small">Dispensar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($result['last_page'] > 1)
            <div class="remarketing-pagination">
                @if ($result['page'] > 1)
                    <a href="{{ route('remarketing.index', $queryFor(['segment' => $segment, 'page' => $result['page'] - 1])) }}">&larr; Anterior</a>
                @endif
                <span>Página {{ $result['page'] }} de {{ $result['last_page'] }}</span>
                @if ($result['page'] < $result['last_page'])
                    <a href="{{ route('remarketing.index', $queryFor(['segment' => $segment, 'page' => $result['page'] + 1])) }}">Próxima &rarr;</a>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
