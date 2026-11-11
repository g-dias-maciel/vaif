@extends('layouts.app')

@section('title', 'Despesas')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Despesas</h1>
            <p class="meta" style="margin-top:6px;">Saídas por mês de competência. O que está pendente e vencido aparece como contas a pagar.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.expenses.create') }}" class="btn btn--small">Nova despesa</a>
            <a href="{{ route('finance.expenses.copy', ['source' => $month->copy()->subMonthNoOverflow()->format('Y-m'), 'target' => $month->format('Y-m')]) }}" class="btn btn--ghost btn--small">Copiar despesas</a>
            <a href="{{ route('finance.months') }}" class="btn btn--ghost btn--small">Visão mensal</a>
            <form method="post" action="{{ route('finance.expenses.generate-recurring') }}" onsubmit="return confirm('Gerar as despesas recorrentes até o próximo mês?');">
                @csrf
                <button type="submit" class="btn btn--ghost btn--small">Gerar recorrentes</button>
            </form>
        </div>
    </div>

    <form method="get" data-auto-filter action="{{ route('finance.expenses.index') }}" class="sales-toolbar">
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" required>
        <select name="status">
            <option value="">Todos os status</option>
            <option value="pending" @selected(request('status') === 'pending')>A pagar</option>
            <option value="paid" @selected(request('status') === 'paid')>Pagas</option>
            <option value="overdue" @selected(request('status') === 'overdue')>Vencidas</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Canceladas</option>
        </select>
        <select name="category">
            <option value="">Todas as categorias</option>
            @foreach ($categories as $value => $label)
                <option value="{{ $value }}" @selected(request('category') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Descrição ou fornecedor">
        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('finance.expenses.index', ['month' => $month->format('Y-m')]) }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    <div class="fin-grid" style="margin-bottom:18px;">
        <div class="fin-card">
            <div class="label">Total do mês</div>
            <div class="value"><x-money :cents="$summary['total']" /></div>
            <div class="hint">{{ $month->format('m/Y') }}</div>
        </div>
        <div class="fin-card">
            <div class="label">Pagas</div>
            <div class="value fin-tone-ok"><x-money :cents="$summary['paid']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">A pagar</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['pending']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Vencidas</div>
            <div class="value fin-tone-danger"><x-money :cents="$summary['overdue']" /></div>
        </div>
    </div>

    @if ($expenses->isEmpty())
        <p class="sales-empty">Nenhuma despesa neste mês com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Despesa</th>
                    <th>Categoria</th>
                    <th>Mês</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Recorrência</th>
                    <th>Valor</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $expense)
                    <tr>
                        <td>
                            <strong>{{ $expense->description }}</strong>
                            @if ($expense->vendor)
                                <div class="sales-sub">{{ $expense->vendor }}</div>
                            @endif
                        </td>
                        <td>{{ $expense->categoryLabel() }}</td>
                        <td>{{ $expense->monthLabel() }}</td>
                        <td>
                            {{ $expense->due_date?->format('d/m/Y') }}
                            @if ($expense->isOverdue())
                                <div class="sales-sub fin-tone-danger">vencida</div>
                            @endif
                        </td>
                        <td><span class="fin-badge fin-badge--{{ $expense->statusTone() }}">{{ $expense->statusLabel() }}</span></td>
                        <td>{{ $expense->recurrenceLabel() }}</td>
                        <td><x-money :cents="$expense->amount_cents" /></td>
                        <td>
                            <div class="fin-actions">
                                <a href="{{ route('finance.expenses.edit', $expense) }}" class="btn btn--ghost btn--small">Editar</a>
                                @if ($expense->status === 'pending')
                                    <form method="post" action="{{ route('finance.expenses.paid', $expense) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--small">Pagar</button>
                                    </form>
                                    <form method="post" action="{{ route('finance.expenses.cancel', $expense) }}" onsubmit="return confirm('Cancelar esta despesa?');">
                                        @csrf
                                        <button type="submit" class="btn btn--ghost btn--small">Cancelar</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $expenses->links() }}</div>
    @endif
</div>
@endsection
