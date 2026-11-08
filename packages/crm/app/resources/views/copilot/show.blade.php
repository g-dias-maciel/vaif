@extends('layouts.app')

@section('title', $report->label())

@section('content')
<style>
    .copilot-table { width: 100%; border-collapse: collapse; }
    .copilot-table th, .copilot-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .copilot-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .copilot-finding { border: 1px solid var(--border); border-left: 4px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; }
    .copilot-finding--info { border-left-color: #7a7a7a; }
    .copilot-finding--warning { border-left-color: var(--gold); }
    .copilot-finding--critical { border-left-color: var(--danger, #d05a5a); }
    .copilot-chip { display: inline-block; font-size: .66rem; letter-spacing: .4px; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
    .copilot-chip--crm { color: var(--gold); border-color: var(--gold); }
    .copilot-chip--bad { color: var(--danger, #d05a5a); border-color: var(--danger, #d05a5a); }
    .copilot-pre { white-space: pre-line; line-height: 1.6; }
    .copilot-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
    .copilot-stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
    .copilot-stat strong { display: block; font-size: 1.45rem; color: var(--gold); line-height: 1.1; }
    .copilot-stat span { display: block; color: var(--muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .6px; margin-top: 4px; }
</style>

<a href="{{ route('copilot.index') }}" class="back">&larr; Copiloto</a>

<div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <div>
        <h1 class="page-title" style="margin-bottom:4px;">{{ $report->label() }}</h1>
        <div class="meta">
            {{ $report->period_start?->format('d/m/Y H:i') }} — {{ $report->period_end?->format('d/m/Y H:i') }}
            · status {{ $report->status }}
            @if ($report->model) · {{ $report->model }} @endif
            @if ($report->delivered_at) · e-mail {{ $report->delivered_at->format('d/m H:i') }} @endif
        </div>
    </div>
    <a href="{{ url()->current() }}" class="btn btn--ghost">Atualizar</a>
</div>

<div class="panel" style="margin-bottom:18px;">
    <h2 class="section-title" style="margin-top:0;">Resumo</h2>
    <p class="copilot-pre" style="margin:0;">{{ $report->narrative ?: 'Sem resumo.' }}</p>
</div>

@if (! empty($report->findings))
    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Achados e sugestões</h2>

        @foreach ($report->findings as $finding)
            <div class="copilot-finding copilot-finding--{{ $finding['severity'] ?? 'info' }}">
                <div class="meta" style="text-transform:uppercase; letter-spacing:.6px;">{{ $finding['severity'] ?? 'info' }}</div>
                <h3 style="margin:2px 0 6px;">{{ $finding['title'] ?? '' }}</h3>
                <div class="copilot-pre">{{ $finding['body'] ?? '' }}</div>

                @if (! empty($finding['evidence']))
                    <div class="meta" style="margin-top:8px;">Evidência: {{ implode(' · ', $finding['evidence']) }}</div>
                @endif

                @if (! empty($finding['suggested_action']))
                    <div class="meta" style="margin-top:8px;"><strong style="color:var(--gold);">Sugestão:</strong> {{ $finding['suggested_action'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif

@php($facts = $report->facts ?? [])

@if ($report->kind === 'ops')
    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Operação — últimos {{ $facts['window_hours'] ?? 24 }}h</h2>

        <div class="copilot-grid" style="margin-bottom:14px;">
            <div class="copilot-stat">
                <strong>{{ $facts['queue']['pending'] ?? '—' }}</strong>
                <span>Jobs pendentes</span>
            </div>
            <div class="copilot-stat">
                <strong>{{ $facts['queue']['oldest_age_minutes'] ?? '—' }} min</strong>
                <span>Job mais antigo</span>
            </div>
            <div class="copilot-stat">
                <strong>{{ $facts['failed_jobs']['recent'] ?? '—' }}</strong>
                <span>Falhas recentes</span>
            </div>
            <div class="copilot-stat">
                <strong>{{ $facts['handoffs']['open'] ?? '—' }}</strong>
                <span>Handoffs abertos</span>
            </div>
            <div class="copilot-stat">
                <strong>{{ $facts['stuck_drafts']['pending_over_threshold'] ?? '—' }}</strong>
                <span>Drafts presos</span>
            </div>
            <div class="copilot-stat">
                <strong>{{ $facts['ingest_drops']['total'] ?? '—' }}</strong>
                <span>Drops de ingestão</span>
            </div>
        </div>

        <h3>Webhooks WAHA</h3>
        <table class="copilot-table">
            <thead><tr><th>Artista</th><th>Modo</th><th>Sessão</th><th>Destino</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($facts['webhooks'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['artist'] ?? '—' }}</td>
                        <td>{{ $row['reply_mode'] ?? '—' }}</td>
                        <td><code>{{ $row['session'] ?? '—' }}</code></td>
                        <td>
                            <span class="copilot-chip {{ ($row['webhook'] ?? '') === 'crm' ? 'copilot-chip--crm' : (($row['webhook'] ?? '') === 'n8n' ? '' : 'copilot-chip--bad') }}">
                                {{ $row['webhook'] ?? 'unknown' }}
                            </span>
                            @if (! empty($row['url']))
                                <div class="meta" style="word-break:break-all;">{{ $row['url'] }}</div>
                            @endif
                        </td>
                        <td>{{ $row['session_status'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="meta">Nenhuma sessão WAHA encontrada.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h3 style="margin-top:20px;">Handoffs abertos</h3>
        <table class="copilot-table">
            <thead><tr><th>Artista</th><th>Lead</th><th>Motivo</th><th>Idade</th></tr></thead>
            <tbody>
                @forelse ($facts['handoffs']['oldest'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['artist'] ?? '—' }}</td>
                        <td>{{ $row['lead'] ?? '—' }}</td>
                        <td>{{ $row['reason'] ?? '—' }}</td>
                        <td>{{ $row['age_hours'] !== null ? $row['age_hours'].'h' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="meta">Nenhum handoff aberto.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h3 style="margin-top:20px;">Drops de ingestão</h3>
        <table class="copilot-table">
            <thead><tr><th>Motivo</th><th>Total</th></tr></thead>
            <tbody>
                @forelse ($facts['ingest_drops']['by_reason'] ?? [] as $row)
                    <tr><td>{{ $row['reason'] }}</td><td>{{ $row['total'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="meta">Nenhum drop no período.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if (! empty($facts['failed_jobs']['samples']))
            <h3 style="margin-top:20px;">Falhas recentes</h3>
            @foreach ($facts['failed_jobs']['samples'] as $failure)
                <div class="copilot-finding copilot-finding--warning">
                    <div class="meta">{{ $failure['queue'] ?? '—' }} · {{ $failure['failed_at'] ?? '' }}</div>
                    <div class="copilot-pre" style="font-size:.8rem;">{{ $failure['exception'] ?? '' }}</div>
                </div>
            @endforeach
        @endif
    </div>
@endif

@if ($report->kind === 'calibration')
    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Calibração — últimos {{ $facts['window_days'] ?? 7 }} dias</h2>

        <div class="copilot-grid" style="margin-bottom:14px;">
            <div class="copilot-stat"><strong>{{ $facts['totals']['drafts'] ?? 0 }}</strong><span>Drafts</span></div>
            <div class="copilot-stat"><strong>{{ $facts['totals']['compared'] ?? 0 }}</strong><span>Comparados</span></div>
            <div class="copilot-stat"><strong>{{ $facts['totals']['unacceptable'] ?? 0 }}</strong><span>Não aceitáveis</span></div>
            <div class="copilot-stat"><strong>{{ $facts['totals']['stuck_pending'] ?? 0 }}</strong><span>Drafts presos</span></div>
        </div>

        @foreach ($facts['artists'] ?? [] as $row)
            <div class="copilot-finding">
                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <h3 style="margin:0;">{{ $row['artist'] }}</h3>
                    <span class="copilot-chip {{ ($row['gates']['passed'] ?? false) ? 'copilot-chip--crm' : 'copilot-chip--bad' }}">
                        {{ ($row['gates']['passed'] ?? false) ? 'gates pass' : 'gates fail' }}
                    </span>
                </div>
                <div class="meta" style="margin:6px 0 10px;">
                    modo {{ $row['reply_mode'] }} · {{ $row['drafts']['total'] ?? 0 }} drafts
                    · {{ $row['drafts']['compared'] ?? 0 }} comparados
                    · {{ $row['drafts']['unacceptable'] ?? 0 }} não aceitáveis
                    · aceitáveis {{ (int) round(($row['gates']['acceptable_rate'] ?? 0) * 100) }}%
                </div>

                @if (! empty($row['top_violation_rules']))
                    <div class="meta"><strong>Regras nos drafts:</strong>
                        {{ collect($row['top_violation_rules'])->map(fn ($r) => $r['rule'].' ('.$r['total'].')')->implode(' · ') }}
                    </div>
                @endif

                @if (! empty($row['top_missing']))
                    <div class="meta"><strong>Perguntas faltando:</strong>
                        {{ collect($row['top_missing'])->map(fn ($r) => $r['missing'].' ('.$r['total'].')')->implode(' · ') }}
                    </div>
                @endif

                @if (! empty($row['samples']))
                    <div style="margin-top:10px;">
                        @foreach ($row['samples'] as $sample)
                            <div style="border-top:1px solid var(--border); padding-top:8px; margin-top:8px;">
                                <div class="meta">#{{ $sample['id'] }} · {{ $sample['intent'] }} · {{ $sample['verdict'] ?? '—' }}</div>
                                <div class="copilot-pre"><strong>Beatriz:</strong> {{ $sample['draft'] ?? '' }}</div>
                                <div class="copilot-pre"><strong>Artista:</strong> {{ $sample['artist'] ?? '' }}</div>
                                @if (! empty($sample['rationale']))
                                    <div class="meta">{{ $sample['rationale'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection
