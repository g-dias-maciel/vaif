@extends('layouts.app')

@section('title', 'Performance')

@section('content')
<h1 class="page-title">Performance — funil de conversa</h1>

<form method="get" action="{{ route('metrics.index') }}" class="period-form">
    <label for="period">Período</label>
    <select name="period" id="period" onchange="this.form.submit()">
        @foreach (\App\Support\Periods::options() as $key => $label)
            <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
        @endforeach
    </select>
</form>

<p class="meta" style="max-width:820px; margin-bottom:18px;">
    Novos leads do período e o que aconteceu com eles. Fechamento = leads que agendaram ÷ novos leads.
    Resposta, orçamento e agendamento são contados a partir da conversa registrada.
</p>

<div class="panel">
    <div style="overflow-x:auto;">
        <table class="calib-table">
            <thead>
                <tr>
                    <th>Artista</th>
                    <th>Modo</th>
                    <th>Novos leads</th>
                    <th>Respondidos</th>
                    <th>1ª resposta</th>
                    <th>Qualificados</th>
                    <th>Orçamentos</th>
                    <th>Agendamentos</th>
                    <th>Fechados</th>
                    <th>Perdidos</th>
                    <th>Sem resposta</th>
                    <th>Fechamento</th>
                    <th>Orç. → ag.</th>
                    <th>Ag. → fech.</th>
                    <th>Auditoria</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($a = $row['artist'])
                    @php($m = $row['metrics'])
                    @php($pct = fn (float $v): string => number_format($v * 100, 1, ',', '.').'%')
                    <tr>
                        <td>
                            <strong>{{ $a->displayName() }}</strong>
                            <div class="meta">{{ $a->status }}</div>
                        </td>
                        <td><span class="calib-mode calib-mode--{{ $a->settings?->reply_mode ?? 'draft' }}">{{ $a->settings?->reply_mode ?? 'draft' }}</span></td>
                        <td>{{ $m['leads'] }}</td>
                        <td>{{ $m['responded'] }} <span class="meta">({{ $pct($m['rates']['response']) }})</span></td>
                        <td>{{ $m['first_response_minutes'] !== null ? $m['first_response_minutes'].' min' : '—' }}</td>
                        <td>{{ $m['qualified'] }}</td>
                        <td>{{ $m['quoted'] }}</td>
                        <td>{{ $m['booked'] }}</td>
                        <td>{{ $m['closed'] }}</td>
                        <td>{{ $m['lost'] }}</td>
                        <td>{{ $m['ghosted'] }}</td>
                        <td><strong>{{ $pct($m['rates']['closing']) }}</strong></td>
                        <td>{{ $pct($m['rates']['quote_to_booking']) }}</td>
                        <td>{{ $pct($m['rates']['booking_to_closed']) }}</td>
                        <td>
                            @if ($row['audit']['avg_score'] !== null)
                                <strong>{{ number_format($row['audit']['avg_score'], 1, ',', '.') }}</strong>
                                <span class="meta">({{ $row['audit']['count'] }})</span>
                            @else
                                <span class="meta">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="15" class="meta">Nenhum artista cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
