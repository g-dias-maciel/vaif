@extends('layouts.app')

@section('title', 'Financeiro')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Financeiro</h1>
            <p class="meta" style="margin-top:6px;">Receita, cobranças e atrasos. Cartão entra como pagamento; PIX entra como cobrança.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.invoices.create') }}" class="btn btn--small">Nova cobrança</a>
            <a href="{{ route('finance.expenses.create') }}" class="btn btn--ghost btn--small">Nova despesa</a>
            <a href="{{ route('finance.months') }}" class="btn btn--ghost btn--small">Visão mensal</a>
            <a href="{{ route('finance.clients') }}" class="btn btn--ghost btn--small">Clientes e contratos</a>
            <a href="{{ route('finance.payments.index') }}" class="btn btn--ghost btn--small">Pagamentos</a>
        </div>
    </div>

    @if ($withoutContract > 0)
        <div class="flash" style="background:#2a1f0a;border-color:#fbbf24;color:#fde68a;">
            <strong>{{ $withoutContract }}</strong> cliente(s) ativo(s) sem contrato vinculado.
            <a href="{{ route('finance.clients', ['without_contract' => 1]) }}" style="color:#fde68a;text-decoration:underline;">Vincular agora</a>.
        </div>
    @endif

    <div class="fin-grid">
        <div class="fin-card">
            <div class="label">MRR</div>
            <div class="value fin-tone-ok"><x-money :cents="$mrr" /></div>
            <div class="hint">{{ $contractCount }} contrato(s) ativo(s)</div>
        </div>
        <div class="fin-card">
            <div class="label">Recebido no mês</div>
            <div class="value"><x-money :cents="$received" /></div>
            <div class="hint">Cartão: <x-money :cents="$receivedCard" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Em aberto</div>
            <div class="value fin-tone-pending"><x-money :cents="$outstanding" /></div>
            <div class="hint">Próximos 30 dias: <x-money :cents="$invoicesNext30" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Atrasado</div>
            <div class="value fin-tone-danger"><x-money :cents="$overdue" /></div>
            <div class="hint">{{ $overdueCount }} cobrança(s) em atraso</div>
        </div>
        <div class="fin-card">
            <div class="label">Contratos</div>
            <div class="value">{{ $cardCount }} <span class="fin-note">cartão</span> / {{ $pixCount }} <span class="fin-note">PIX</span></div>
            <div class="hint">Cobrança por cartão não gera invoice.</div>
        </div>
        <div class="fin-card">
            <div class="label">Despesas do mês</div>
            <div class="value fin-tone-pending"><x-money :cents="$monthly['expenses_cents']" /></div>
            <div class="hint">Pagas: <x-money :cents="$monthly['expenses_paid_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">A pagar no mês</div>
            <div class="value fin-tone-pending"><x-money :cents="$monthly['payables_cents']" /></div>
            <div class="hint">Vencido: <x-money :cents="$monthly['overdue_payables_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Lucro caixa</div>
            <div class="value {{ $monthly['profit_cash_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$monthly['profit_cash_cents']" /></div>
            <div class="hint">Resultado: <x-money :cents="$monthly['profit_accrual_cents']" /></div>
        </div>
    </div>

    <div class="fin-section">
        <h2>Próximos vencimentos e cobranças</h2>
        @if ($upcoming->isEmpty())
            <p class="sales-empty">Nenhum contrato com cobrança nos próximos 30 dias.</p>
        @else
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Plano</th>
                        <th>Modo</th>
                        <th>Próxima data</th>
                        <th>Valor mensal</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($upcoming as $contract)
                        <tr>
                            <td><strong>{{ $contract->prospect?->name ?? '—' }}</strong></td>
                            <td>{{ $contract->planLabel() }}</td>
                            <td>{{ $contract->billingModeLabel() }}</td>
                            <td>{{ $contract->nextBillingDate()?->format('d/m/Y') ?? '—' }}</td>
                            <td><x-money :cents="$contract->monthly_value_cents" /></td>
                            <td>
                                @if ($contract->prospect)
                                    <a href="{{ route('finance.contracts.edit', $contract) }}" class="btn btn--ghost btn--small">Contrato</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="fin-section">
        <h2>Contas a pagar nos próximos 30 dias</h2>
        @if ($upcomingExpenses->isEmpty())
            <p class="sales-empty">Nenhuma despesa pendente nos próximos 30 dias.</p>
        @else
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Despesa</th>
                        <th>Categoria</th>
                        <th>Vencimento</th>
                        <th>Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($upcomingExpenses as $expense)
                        <tr>
                            <td>
                                <strong>{{ $expense->description }}</strong>
                                @if ($expense->vendor)
                                    <div class="sales-sub">{{ $expense->vendor }}</div>
                                @endif
                            </td>
                            <td>{{ $expense->categoryLabel() }}</td>
                            <td>{{ $expense->due_date?->format('d/m/Y') }}</td>
                            <td><x-money :cents="$expense->amount_cents" /></td>
                            <td><a href="{{ route('finance.expenses.edit', $expense) }}" class="btn btn--ghost btn--small">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-top:22px;">
        <div>
            <h2 style="font-size:1rem; margin:0 0 10px;">Cobranças recentes</h2>
            @if ($recentInvoices->isEmpty())
                <p class="sales-empty">Nenhuma cobrança ainda.</p>
            @else
                <table class="sales-table">
                    <thead><tr><th>Número</th><th>Cliente</th><th>Status</th><th>Valor</th></tr></thead>
                    <tbody>
                        @foreach ($recentInvoices as $invoice)
                            <tr>
                                <td><a href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                                <td>{{ $invoice->prospect?->name ?? '—' }}</td>
                                <td><span class="fin-badge fin-badge--{{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></td>
                                <td><x-money :cents="$invoice->amount_cents" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div>
            <h2 style="font-size:1rem; margin:0 0 10px;">Pagamentos recentes</h2>
            @if ($recentPayments->isEmpty())
                <p class="sales-empty">Nenhum pagamento registrado.</p>
            @else
                <table class="sales-table">
                    <thead><tr><th>Cliente</th><th>Método</th><th>Data</th><th>Valor</th></tr></thead>
                    <tbody>
                        @foreach ($recentPayments as $payment)
                            <tr>
                                <td>{{ $payment->prospect?->name ?? '—' }}</td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td>{{ $payment->paid_at?->format('d/m/Y') }}</td>
                                <td><x-money :cents="$payment->amount_cents" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <p class="fin-note" style="margin-top:22px;">
        Geração automática: <code>php artisan finance:generate-invoices</code> · atrasos: <code>php artisan finance:mark-overdue</code>.
        <a href="{{ route('finance.settings') }}">Configuração financeira</a>.
    </p>
</div>
@endsection
