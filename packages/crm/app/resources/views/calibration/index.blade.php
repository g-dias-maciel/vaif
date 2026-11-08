@extends('layouts.app')

@section('title', 'Calibração do agente')

@section('content')
<style>
    .calib-table { width: 100%; border-collapse: collapse; }
    .calib-table th, .calib-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
    .calib-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .calib-table tr:last-child td { border-bottom: none; }
    .calib-mode { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: .68rem; letter-spacing: .4px; text-transform: uppercase; border: 1px solid var(--border); }
    .calib-mode--auto { color: #fff; background: var(--gold); border-color: var(--gold); }
    .calib-mode--draft { color: var(--gold); border-color: var(--gold); }
    .calib-mode--off { color: var(--muted); }
    .calib-pass { color: var(--gold); font-weight: 600; }
    .calib-fail { color: var(--danger, #d05a5a); font-weight: 600; }
</style>

<div class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:10px;">
        <h1 style="margin:0;">Calibração do agente</h1>
        <a href="{{ route('artists.index') }}" class="btn btn--ghost">Artistas</a>
    </div>

    <p class="meta" style="max-width:820px;">
        Fase de aprendizado por artista: a Beatriz lê as mensagens, prepara a resposta e compara com a resposta real do artista.
        Em <strong>draft</strong> nada é enviado ao lead. A promoção para <strong>auto</strong> só passa quando os gates fecham.
    </p>

    @if (! ($schemaReady ?? true))
        <div class="flash error" style="margin-bottom:14px;">
            A migração de calibração ainda não foi aplicada. Rode
            <code>packages/crm/db/migrations/0033_agent_calibration.sql</code> para liberar os modos e os rascunhos.
        </div>
    @endif

    <table class="calib-table">
        <thead>
            <tr>
                <th>Artista</th>
                <th>Sessão</th>
                <th>Modo</th>
                <th>Comparados</th>
                <th>Aceitáveis</th>
                <th>Resposta</th>
                <th>Gates</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php($a = $row['artist'])
                @php($r = $row['report'])
                <tr>
                    <td>
                        <strong>{{ $a->displayName() }}</strong>
                        <div class="meta">{{ $a->status }}</div>
                    </td>
                    <td><code>{{ $a->wa_session_slug ?: '—' }}</code></td>
                    <td>
                        <span class="calib-mode calib-mode--{{ $r['reply_mode'] }}">{{ $r['reply_mode'] }}</span>
                    </td>
                    <td>{{ $r['totals']['compared'] }} / {{ $r['totals']['eligible'] }}</td>
                    <td>{{ (int) round($r['rates']['acceptable'] * 100) }}%</td>
                    <td>{{ (int) round($r['rates']['response'] * 100) }}%</td>
                    <td class="{{ $r['passed'] ? 'calib-pass' : 'calib-fail' }}">
                        {{ $r['passed'] ? 'PASS' : 'FAIL' }}
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a href="{{ route('calibration.show', $a) }}" class="btn btn--ghost btn--small">Abrir</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="meta">Nenhum artista cadastrado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
