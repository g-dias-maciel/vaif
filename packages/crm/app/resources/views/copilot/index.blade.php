@extends('layouts.app')

@section('title', 'Copiloto')

@section('content')
<style>
    .copilot-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); gap: 16px; }
    .copilot-card { background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
    .copilot-card h2 { margin: 0 0 4px; font-size: 1.05rem; }
    .copilot-card .meta { margin-bottom: 10px; }
    .copilot-table { width: 100%; border-collapse: collapse; }
    .copilot-table th, .copilot-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .copilot-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .copilot-chip { display: inline-block; font-size: .66rem; letter-spacing: .4px; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
    .copilot-chip--ready { color: var(--gold); border-color: var(--gold); }
    .copilot-chip--queued { color: var(--muted); }
    .copilot-chip--failed { color: var(--danger, #d05a5a); border-color: var(--danger, #d05a5a); }
</style>

<div class="panel">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="margin:0 0 6px;">Copiloto</h1>
            <p class="meta" style="max-width:820px;">
                Relatórios internos de operação e calibração. O Copiloto só lê e sugere; nenhuma ação é executada automaticamente.
            </p>
        </div>
        <a href="{{ route('calibration.index') }}" class="btn btn--ghost">Calibração</a>
    </div>

    @if (! $schemaReady)
        <div class="flash error" style="margin-top:14px;">
            A migração do Copiloto ainda não foi aplicada. Rode
            <code>packages/crm/db/migrations/0034_copilot_reports.sql</code>.
        </div>
    @endif
</div>

<div class="copilot-grid" style="margin-bottom:20px;">
    @foreach ([['ops', 'Ops diário', $latestOps], ['calibration', 'Calibração semanal', $latestCalibration]] as [$kind, $label, $report])
        <div class="copilot-card">
            <h2>{{ $label }}</h2>

            @if (! $report)
                <p class="meta">Ainda não existe relatório.</p>
            @else
                <div class="meta">
                    <span class="copilot-chip copilot-chip--{{ $report->status }}">{{ $report->status }}</span>
                    {{ $report->period_end?->format('d/m/Y H:i') }}
                </div>

                @if ($report->status === 'queued')
                    <p class="meta">Gerando… atualize a página em alguns segundos.</p>
                @elseif ($report->status === 'failed')
                    <p class="meta" style="color: var(--danger, #d05a5a);">{{ $report->narrative }}</p>
                @else
                    <p style="line-height:1.6;">{{ \Illuminate\Support\Str::limit($report->narrative, 280) }}</p>
                    <p class="meta">{{ count($report->findings ?? []) }} achado(s) · {{ $report->model }}</p>
                    <a href="{{ route('copilot.show', $report) }}" class="btn btn--ghost btn--small">Abrir relatório</a>
                @endif
            @endif

            @if ($schemaReady)
                <form method="post" action="{{ route('copilot.generate') }}" style="margin-top:14px;">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <button type="submit" class="btn btn--small">Gerar agora</button>
                </form>
            @endif
        </div>
    @endforeach
</div>

<div class="panel">
    <h2 class="section-title" style="margin-top:0;">Histórico</h2>

    @if ($reports->isEmpty())
        <p class="meta">Nenhum relatório gerado ainda.</p>
    @else
        <table class="copilot-table">
            <thead>
                <tr><th>Tipo</th><th>Período</th><th>Status</th><th>Gerado</th><th>E-mail</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($reports as $report)
                    <tr>
                        <td>{{ $report->label() }}</td>
                        <td>{{ $report->period_end?->format('d/m/Y H:i') }}</td>
                        <td><span class="copilot-chip copilot-chip--{{ $report->status }}">{{ $report->status }}</span></td>
                        <td>{{ $report->generated_at?->format('d/m H:i') ?? '—' }}</td>
                        <td>{{ $report->delivered_at?->format('d/m H:i') ?? '—' }}</td>
                        <td style="text-align:right;">
                            <a href="{{ route('copilot.show', $report) }}" class="btn btn--ghost btn--small">Abrir</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
