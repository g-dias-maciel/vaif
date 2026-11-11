@extends('layouts.app')

@section('title', 'Copiar despesas')

@section('content')
@include('finance.partials.styles')

@php
    $sameMonth = $source->format('Y-m') === $target->format('Y-m');
    $copyable = ! $sameMonth && $sourceExpenses->isNotEmpty();
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Copiar despesas</h1>
            <p class="meta" style="margin-top:6px;">
                Copie as despesas de um mês para outro. As cópias entram como <strong>A pagar</strong>.
            </p>
        </div>
        <a href="{{ route('finance.expenses.index', ['month' => $target->format('Y-m')]) }}" class="btn btn--ghost btn--small">Voltar para despesas</a>
    </div>

    @if (session('error'))
        <div class="sales-errors">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="get" data-auto-filter action="{{ route('finance.expenses.copy') }}" class="sales-toolbar">
        <label class="sales-check" style="flex-direction:column; align-items:flex-start; gap:4px;">
            Mês de origem
            <input type="month" name="source" value="{{ $source->format('Y-m') }}" required>
        </label>
        <label class="sales-check" style="flex-direction:column; align-items:flex-start; gap:4px;">
            Mês de destino
            <input type="month" name="target" value="{{ $target->format('Y-m') }}" required>
        </label>
        <span class="meta">A prévia atualiza ao selecionar o mês.</span>
    </form>

    <div class="fin-grid" style="margin-bottom:18px;">
        <div class="fin-card">
            <div class="label">Despesas na origem</div>
            <div class="value">{{ $sourceExpenses->count() }}</div>
            <div class="hint">{{ $source->format('m/Y') }}</div>
        </div>
        <div class="fin-card">
            <div class="label">Valor total</div>
            <div class="value"><x-money :cents="$sourceTotal" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Já copiadas para o destino</div>
            <div class="value">{{ $alreadyCopied }}</div>
            <div class="hint">{{ $target->format('m/Y') }}</div>
        </div>
    </div>

    @if ($sameMonth)
        <div class="flash" style="background:#2a0a0a;border-color:var(--danger);color:#fecaca;">
            Escolha meses diferentes para copiar as despesas.
        </div>
    @elseif ($sourceExpenses->isEmpty())
        <p class="sales-empty">Nenhuma despesa no mês de origem.</p>
    @else
        <h2 style="margin:0 0 10px; font-size:1rem;">Prévia — {{ $source->format('m/Y') }}</h2>

        <table class="sales-table">
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Vencimento na origem</th>
                    <th>Recorrência</th>
                    <th>Status</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sourceExpenses as $expense)
                    <tr>
                        <td>
                            <strong>{{ $expense->description }}</strong>
                            @if ($expense->notes)
                                <div class="sales-sub">{{ \Illuminate\Support\Str::limit($expense->notes, 80) }}</div>
                            @endif
                        </td>
                        <td>{{ $expense->categoryLabel() }}</td>
                        <td>{{ $expense->due_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $expense->recurrenceLabel() }}</td>
                        <td><span class="fin-badge fin-badge--{{ $expense->statusTone() }}">{{ $expense->statusLabel() }}</span></td>
                        <td><x-money :cents="$expense->amount_cents" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5"><strong>Total</strong></td>
                    <td><strong><x-money :cents="$sourceTotal" /></strong></td>
                </tr>
            </tfoot>
        </table>

        <form method="post" action="{{ route('finance.expenses.copy.store') }}" style="margin-top:20px;">
            @csrf
            <input type="hidden" name="source" value="{{ $source->format('Y-m') }}">
            <input type="hidden" name="target" value="{{ $target->format('Y-m') }}">

            <label class="sales-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <input type="checkbox" name="mark_monthly" value="1" @checked(old('mark_monthly', $sourceHasMonthly))>
                Manter como despesa mensal — o gerador repete automaticamente nos próximos meses
            </label>

            <p class="meta" style="margin-bottom:12px;">
                @if ($alreadyCopied > 0)
                    {{ $alreadyCopied }} despesa(s) da origem já foram copiadas para {{ $target->format('m/Y') }} e serão ignoradas.
                @endif
                O vencimento mantém o mesmo dia; se o mês de destino for mais curto, usa o último dia.
            </p>

            <button type="submit" class="btn" @disabled(! $copyable)>
                Copiar {{ $sourceExpenses->count() }} despesa(s) para {{ $target->format('m/Y') }}
            </button>
        </form>
    @endif
</div>
@endsection
