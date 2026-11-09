@extends('layouts.app')

@section('title', $expense->exists ? 'Editar despesa' : 'Nova despesa')

@section('content')
@include('finance.partials.styles')

@php
    $editing = $expense->exists;
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $editing ? 'Editar despesa' : 'Nova despesa' }}</h1>
            <p class="meta" style="margin-top:6px;">A despesa entra no mês de competência e nas contas a pagar até ser marcada como paga.</p>
        </div>
        <a href="{{ route('finance.expenses.index') }}" class="btn btn--ghost btn--small">Voltar</a>
    </div>

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ $editing ? route('finance.expenses.update', $expense) : route('finance.expenses.store') }}" class="sales-form">
        @csrf
        @if ($editing)
            @method('put')
        @endif

        <label>
            Descrição
            <input type="text" name="description" value="{{ old('description', $expense->description) }}" required>
        </label>

        <label>
            Fornecedor
            <input type="text" name="vendor" value="{{ old('vendor', $expense->vendor) }}" placeholder="Opcional">
        </label>

        <label>
            Categoria
            <select name="category" required>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}" @selected(old('category', $expense->category) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Valor (R$)
            <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $expense->amount_cents ? number_format($expense->amount_cents / 100, 2, '.', '') : '') }}" required>
        </label>

        <label>
            Mês de competência
            <input type="month" name="competence_month" value="{{ old('competence_month', $expense->competence_month?->format('Y-m') ?? now()->format('Y-m')) }}" required>
        </label>

        <label>
            Vencimento
            <input type="date" name="due_date" value="{{ old('due_date', $expense->due_date?->toDateString() ?? now()->toDateString()) }}" required>
        </label>

        <label>
            Status
            <select name="status" required>
                @foreach (['pending' => 'A pagar', 'paid' => 'Paga', 'cancelled' => 'Cancelada'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $expense->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Pago em
            <input type="date" name="paid_at" value="{{ old('paid_at', $expense->paid_at?->toDateString()) }}">
        </label>

        <label>
            Forma de pagamento
            <select name="payment_method">
                <option value="">Não informado</option>
                @foreach ($methods as $value => $label)
                    <option value="{{ $value }}" @selected(old('payment_method', $expense->payment_method) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Recorrência
            <select name="recurrence" required>
                <option value="one_off" @selected(old('recurrence', $expense->recurrence) === 'one_off')>Avulsa</option>
                <option value="monthly" @selected(old('recurrence', $expense->recurrence) === 'monthly')>Mensal</option>
            </select>
        </label>

        <label class="full">
            Observações
            <textarea name="notes">{{ old('notes', $expense->notes) }}</textarea>
        </label>

        <div class="full">
            <p class="fin-note" style="margin-bottom:10px;">
                Despesas mensais são clonadas automaticamente para o mês seguinte todos os dias às 06:20. A primeira ocorrência
                precisa existir neste mês; a partir dela o sistema reproduz o mesmo valor e vencimento.
            </p>
            <button type="submit" class="btn">{{ $editing ? 'Salvar despesa' : 'Criar despesa' }}</button>
        </div>
    </form>
</div>
@endsection
