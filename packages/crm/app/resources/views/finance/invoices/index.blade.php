@extends('layouts.app')

@section('title', 'Cobranças')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Cobranças</h1>
            <p class="meta" style="margin-top:6px;">PIX e cobranças manuais. Cartão não aparece aqui: entra como pagamento.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.invoices.create') }}" class="btn btn--small">Nova cobrança</a>
            <a href="{{ route('finance.dashboard') }}" class="btn btn--ghost btn--small">Financeiro</a>
        </div>
    </div>

    <div class="fin-grid" style="margin-bottom:18px;">
        <div class="fin-card">
            <div class="label">Em aberto</div>
            <div class="value fin-tone-pending"><x-money :cents="$outstanding" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Atrasado</div>
            <div class="value fin-tone-danger"><x-money :cents="$overdue" /></div>
        </div>
    </div>

    <form method="get" action="{{ route('finance.invoices.index') }}" class="sales-toolbar">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Número ou cliente">
        <select name="status">
            <option value="">Todos os status</option>
            @foreach (['draft' => 'Rascunho', 'sent' => 'Enviada', 'paid' => 'Paga', 'overdue' => 'Atrasada', 'cancelled' => 'Cancelada'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <label class="sales-check">
            <input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))>
            Só atrasadas
        </label>
        <input type="date" name="from" value="{{ request('from') }}" title="Vencimento de">
        <input type="date" name="to" value="{{ request('to') }}" title="Vencimento até">
        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('finance.invoices.index') }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    @if ($invoices->isEmpty())
        <p class="sales-empty">Nenhuma cobrança com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Cliente</th>
                    <th>Período</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Valor</th>
                    <th>Pago</th>
                    <th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('finance.invoices.show', $invoice) }}"><strong>{{ $invoice->number }}</strong></a></td>
                        <td>{{ $invoice->prospect?->name ?? '—' }}</td>
                        <td>{{ $invoice->periodLabel() }}</td>
                        <td>{{ $invoice->due_date?->format('d/m/Y') }}</td>
                        <td><span class="fin-badge fin-badge--{{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></td>
                        <td><x-money :cents="$invoice->amount_cents" /></td>
                        <td><x-money :cents="$invoice->paid_cents" /></td>
                        <td><x-money :cents="$invoice->balanceCents()" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
