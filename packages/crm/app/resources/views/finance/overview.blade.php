@extends('layouts.app')

@section('title', 'Financeiro')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Financeiro</h1>
            <p class="meta" style="margin-top:6px;">Visão anual, cobranças, despesas e distribuição de lucros.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.invoices.create') }}" class="btn btn--small">Nova cobrança</a>
            <a href="{{ route('finance.expenses.create') }}" class="btn btn--ghost btn--small">Nova despesa</a>
            <a href="{{ route('finance.months', ['month' => now()->format('Y-m')]) }}" class="btn btn--ghost btn--small">Mês atual</a>
        </div>
    </div>

    @if ($awaiting->isNotEmpty())
        <div class="flash" style="background:#2a1f0a;border-color:#fbbf24;color:#fde68a;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span><strong>{{ $awaiting->count() }}</strong> fatura(s) aguardando aprovação — <x-money :cents="$awaiting->sum('amount_cents')" />.</span>
            <a href="{{ route('finance.invoices.index', ['status' => 'draft']) }}" class="btn btn--ghost btn--small">Revisar</a>
        </div>
    @endif

    @if ($overdue->isNotEmpty())
        <div class="flash" style="background:#2a0a0a;border-color:var(--danger);color:#fecaca;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span><strong>{{ $overdue->count() }}</strong> fatura(s) atrasada(s) — <x-money :cents="$overdue->sum(fn ($invoice) => $invoice->balanceCents())" />.</span>
            <form method="post" action="{{ route('finance.invoices.remind-overdue') }}" onsubmit="return confirm('Enviar cobrança de atraso para todas as faturas vencidas?');">
                @csrf
                <button type="submit" class="btn btn--ghost btn--small">Cobrar todos</button>
            </form>
        </div>
    @endif

    @if ($withoutContract > 0 || $overdueExpenses > 0)
        <div class="flash" style="background:#101a2a;border-color:#60a5fa;color:#bfdbfe;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span>
                @if ($withoutContract > 0)
                    <strong>{{ $withoutContract }}</strong> cliente(s) ativo(s) sem contrato.
                @endif
                @if ($overdueExpenses > 0)
                    {{ $withoutContract > 0 ? ' · ' : '' }}<strong>{{ $overdueExpenses }}</strong> despesa(s) vencida(s).
                @endif
            </span>
            <span class="fin-actions">
                @if ($withoutContract > 0)
                    <a href="{{ route('finance.clients', ['without_contract' => 1]) }}" class="btn btn--ghost btn--small">Contratos</a>
                @endif
                @if ($overdueExpenses > 0)
                    <a href="{{ route('finance.expenses.index', ['status' => 'overdue']) }}" class="btn btn--ghost btn--small">Despesas</a>
                @endif
            </span>
        </div>
    @endif

    <div class="fin-grid" style="margin-bottom:24px;">
        <div class="fin-card">
            <div class="label">Recebido no mês</div>
            <div class="value fin-tone-ok"><x-money :cents="$current['received_cents']" /></div>
            <div class="hint">Cartão: <x-money :cents="$current['received_card_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Saídas pagas</div>
            <div class="value fin-tone-pending"><x-money :cents="$current['expenses_paid_cents']" /></div>
            <div class="hint">A pagar: <x-money :cents="$current['payables_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Lucro líquido</div>
            <div class="value {{ $current['profit_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$current['profit_cents']" /></div>
            <div class="hint">Recebido − saídas pagas</div>
        </div>
        <div class="fin-card">
            <div class="label">Guardado</div>
            <div class="value {{ $current['saved_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$current['saved_cents']" /></div>
            <div class="hint">Lucro − distribuições</div>
        </div>
        <div class="fin-card">
            <div class="label">ROI</div>
            <div class="value {{ ($current['roi_percent'] ?? 0) >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                {{ $current['roi_percent'] !== null ? number_format($current['roi_percent'], 2, ',', '.').'%' : '—' }}
            </div>
            <div class="hint">Lucro ÷ saídas pagas</div>
        </div>
        <div class="fin-card">
            <div class="label">MRR</div>
            <div class="value"><x-money :cents="$current['mrr_cents']" /></div>
            <div class="hint">{{ $current['contracts_count'] }} contrato(s) ativo(s)</div>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
        <h2 style="margin:0;font-size:1rem;">Visão anual</h2>
        <form method="get" action="{{ route('finance.dashboard') }}" class="sales-toolbar" style="margin:0;">
            <select name="year" onchange="this.form.submit()">
                @foreach ($years as $option)
                    <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <table class="sales-table">
        <thead>
            <tr>
                <th>Mês</th>
                <th>A receber</th>
                <th>Recebido</th>
                <th>Saídas</th>
                <th>Lucro líquido</th>
                <th>Guardado</th>
                <th>ROI</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($overview as $row)
                <tr style="{{ $row['key'] === now()->format('Y-m') ? 'background:var(--panel-2);' : '' }}">
                    <td>
                        <a href="{{ route('finance.months', ['month' => $row['key']]) }}"><strong>{{ $row['label'] }}</strong></a>
                        @if ($row['awaiting_approval_count'] > 0)
                            <div class="sales-sub fin-tone-pending">{{ $row['awaiting_approval_count'] }} a aprovar</div>
                        @endif
                    </td>
                    <td>
                        <x-money :cents="$row['receivable_cents']" />
                        @if ($row['invoices_count'] > 0)
                            <div class="sales-sub">{{ $row['invoices_count'] }} cobrança(s)</div>
                        @endif
                    </td>
                    <td><x-money :cents="$row['received_cents']" /></td>
                    <td>
                        <x-money :cents="$row['expenses_paid_cents']" />
                        @if ($row['payables_cents'] > 0)
                            <div class="sales-sub fin-tone-pending">a pagar <x-money :cents="$row['payables_cents']" /></div>
                        @endif
                    </td>
                    <td class="{{ $row['profit_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                        <x-money :cents="$row['profit_cents']" />
                    </td>
                    <td class="{{ $row['saved_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                        <x-money :cents="$row['saved_cents']" />
                    </td>
                    <td class="{{ ($row['roi_percent'] ?? 0) >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                        {{ $row['roi_percent'] !== null ? number_format($row['roi_percent'], 2, ',', '.').'%' : '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total {{ $year }}</strong></td>
                <td><strong><x-money :cents="$overview->sum('receivable_cents')" /></strong></td>
                <td><strong><x-money :cents="$overview->sum('received_cents')" /></strong></td>
                <td><strong><x-money :cents="$overview->sum('expenses_paid_cents')" /></strong></td>
                <td class="{{ $overview->sum('profit_cents') >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><strong><x-money :cents="$overview->sum('profit_cents')" /></strong></td>
                <td><strong><x-money :cents="$overview->sum('saved_cents')" /></strong></td>
                <td>
                    @php($yearExpenses = $overview->sum('expenses_paid_cents'))
                    <strong>{{ $yearExpenses > 0 ? number_format($overview->sum('profit_cents') / $yearExpenses * 100, 2, ',', '.').'%' : '—' }}</strong>
                </td>
            </tr>
        </tfoot>
    </table>

    <p class="fin-note" style="margin-top:14px;">
        <strong>A receber</strong> usa o saldo das cobranças do mês; <strong>Recebido</strong> usa a data dos pagamentos (PIX e cartão).
        <strong>Saídas</strong> e <strong>Lucro</strong> são caixa; <strong>Guardado</strong> é lucro menos distribuições de lucro.
        <strong>ROI</strong> é lucro dividido pelas saídas pagas.
    </p>
</div>
@endsection
