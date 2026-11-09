@extends('layouts.app')

@section('title', 'Visão mensal')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Visão mensal</h1>
            <p class="meta" style="margin-top:6px;">Entradas, saídas, contas a pagar e lucro mês a mês.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.expenses.index', ['month' => $month->format('Y-m')]) }}" class="btn btn--ghost btn--small">Despesas do mês</a>
            <a href="{{ route('finance.dashboard') }}" class="btn btn--ghost btn--small">Financeiro</a>
        </div>
    </div>

    <form method="get" action="{{ route('finance.months') }}" class="sales-toolbar">
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" required>
        <button type="submit" class="btn btn--small">Ver mês</button>
    </form>

    <div class="fin-grid" style="margin-bottom:20px;">
        <div class="fin-card">
            <div class="label">Cobrado</div>
            <div class="value"><x-money :cents="$summary['invoiced_cents']" /></div>
            <div class="hint">{{ $summary['invoices_count'] }} cobrança(s)</div>
        </div>
        <div class="fin-card">
            <div class="label">Recebido</div>
            <div class="value fin-tone-ok"><x-money :cents="$summary['received_cents']" /></div>
            <div class="hint">Cartão: <x-money :cents="$summary['received_card_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Despesas do mês</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['expenses_cents']" /></div>
            <div class="hint">{{ $summary['expenses_count'] }} despesa(s)</div>
        </div>
        <div class="fin-card">
            <div class="label">Despesas pagas</div>
            <div class="value"><x-money :cents="$summary['expenses_paid_cents']" /></div>
            <div class="hint">Base de caixa</div>
        </div>
        <div class="fin-card">
            <div class="label">A pagar</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['payables_cents']" /></div>
            <div class="hint">Vencido: <x-money :cents="$summary['overdue_payables_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Lucro caixa</div>
            <div class="value {{ $summary['profit_cash_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$summary['profit_cash_cents']" /></div>
            <div class="hint">Recebido − despesas pagas</div>
        </div>
        <div class="fin-card">
            <div class="label">Resultado competência</div>
            <div class="value {{ $summary['profit_accrual_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$summary['profit_accrual_cents']" /></div>
            <div class="hint">Cobrado − despesas do mês</div>
        </div>
        <div class="fin-card">
            <div class="label">MRR</div>
            <div class="value"><x-money :cents="$summary['mrr_cents']" /></div>
            <div class="hint">{{ $summary['contracts_count'] }} contrato(s) ativo(s)</div>
        </div>
    </div>

    <div class="fin-grid" style="margin-bottom:20px;">
        <div class="fin-card">
            <div class="label">A receber</div>
            <div class="value"><x-money :cents="$summary['receivable_cents']" /></div>
            <div class="hint">Saldo em aberto das cobranças do mês</div>
        </div>
        <div class="fin-card">
            <div class="label">Recebido PIX</div>
            <div class="value"><x-money :cents="$summary['received_pix_cents']" /></div>
        </div>
    </div>

    <table class="sales-table">
        <thead>
            <tr>
                <th>Mês</th>
                <th>Cobrado</th>
                <th>Recebido</th>
                <th>Despesas</th>
                <th>Desp. pagas</th>
                <th>Lucro caixa</th>
                <th>A pagar</th>
                <th>Resultado</th>
                <th>MRR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($months as $row)
                <tr style="{{ $row['label'] === $month->format('m/Y') ? 'background:var(--panel-2);' : '' }}">
                    <td>
                        <a href="{{ route('finance.months', ['month' => $row['month']->format('Y-m')]) }}"><strong>{{ $row['label'] }}</strong></a>
                    </td>
                    <td><x-money :cents="$row['invoiced_cents']" /></td>
                    <td><x-money :cents="$row['received_cents']" /></td>
                    <td><x-money :cents="$row['expenses_cents']" /></td>
                    <td><x-money :cents="$row['expenses_paid_cents']" /></td>
                    <td class="{{ $row['profit_cash_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                        <x-money :cents="$row['profit_cash_cents']" />
                    </td>
                    <td>
                        <x-money :cents="$row['payables_cents']" />
                        @if ($row['overdue_payables_cents'] > 0)
                            <div class="sales-sub fin-tone-danger">vencido <x-money :cents="$row['overdue_payables_cents']" /></div>
                        @endif
                    </td>
                    <td class="{{ $row['profit_accrual_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                        <x-money :cents="$row['profit_accrual_cents']" />
                    </td>
                    <td><x-money :cents="$row['mrr_cents']" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="fin-note" style="margin-top:16px;">
        <strong>Cobrado</strong> usa o período das cobranças; <strong>Recebido</strong> usa a data dos pagamentos (PIX e cartão).
        <strong>Despesas</strong> usa o mês de competência; <strong>Desp. pagas</strong> usa a data de pagamento.
        <strong>Lucro caixa</strong> é recebido menos despesas pagas; <strong>Resultado</strong> é cobrado menos despesas do mês.
    </p>
</div>
@endsection
