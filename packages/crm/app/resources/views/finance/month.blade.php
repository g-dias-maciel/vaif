@extends('layouts.app')

@section('title', 'Financeiro — '.$month->format('m/Y'))

@section('content')
@include('finance.partials.styles')

@php
    $profit = (int) $summary['profit_cents'];
    $distributable = max(0, $profit);
    $profitRows = $distributions->where('kind', \App\Models\FinanceDistribution::KIND_PROFIT)->keyBy('partner_id');
    $reimbursementRows = $distributions->where('kind', \App\Models\FinanceDistribution::KIND_REIMBURSEMENT)->keyBy('partner_id');
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">{{ $month->format('m/Y') }}</h1>
            <p class="meta" style="margin-top:6px;">Entradas, saídas e distribuição de lucros do mês.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.invoices.create', ['month' => $month->format('Y-m')]) }}" class="btn btn--small">Nova cobrança</a>
            <a href="{{ route('finance.expenses.create', ['month' => $month->format('Y-m')]) }}" class="btn btn--ghost btn--small">Nova despesa</a>
            <a href="{{ route('finance.dashboard', ['year' => $month->year]) }}" class="btn btn--ghost btn--small">Visão anual</a>
        </div>
    </div>

    <form method="get" action="{{ route('finance.months') }}" class="sales-toolbar">
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" required>
        <span class="meta">Atualiza ao selecionar o mês.</span>
    </form>

    <div class="fin-grid" style="margin-bottom:20px;">
        <div class="fin-card">
            <div class="label">Recebido</div>
            <div class="value fin-tone-ok"><x-money :cents="$summary['received_cents']" /></div>
            <div class="hint">Cartão: <x-money :cents="$summary['received_card_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Saídas pagas</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['expenses_paid_cents']" /></div>
            <div class="hint">A pagar: <x-money :cents="$summary['payables_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Lucro líquido</div>
            <div class="value {{ $profit >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$profit" /></div>
            <div class="hint">Recebido − saídas pagas</div>
        </div>
        <div class="fin-card">
            <div class="label">Distribuído</div>
            <div class="value"><x-money :cents="$summary['distributed_profit_cents']" /></div>
            <div class="hint">Reembolsos: <x-money :cents="$summary['distributed_reimbursement_cents']" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Guardado</div>
            <div class="value {{ $summary['saved_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$summary['saved_cents']" /></div>
            <div class="hint">{{ $savedOverride !== null ? 'Valor histórico/manual' : 'Lucro − distribuições' }}</div>
        </div>
        <div class="fin-card">
            <div class="label">ROI</div>
            <div class="value {{ ($summary['roi_percent'] ?? 0) >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}">
                {{ $summary['roi_percent'] !== null ? number_format($summary['roi_percent'], 2, ',', '.').'%' : '—' }}
            </div>
            <div class="hint">Lucro ÷ saídas pagas</div>
        </div>
        <div class="fin-card">
            <div class="label">A receber</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['receivable_cents']" /></div>
            <div class="hint">{{ $summary['awaiting_approval_count'] }} a aprovar</div>
        </div>
        <div class="fin-card">
            <div class="label">A pagar</div>
            <div class="value fin-tone-pending"><x-money :cents="$summary['payables_cents']" /></div>
            <div class="hint">Vencido: <x-money :cents="$summary['overdue_payables_cents']" /></div>
        </div>
    </div>

    <div class="fin-tabs">
        <button type="button" class="fin-tab is-active" data-fin-tab="entradas">Entradas</button>
        <button type="button" class="fin-tab" data-fin-tab="saidas">Saídas</button>
        <button type="button" class="fin-tab" data-fin-tab="distribuicao">Distribuição</button>
    </div>

    {{-- ENTRADAS --------------------------------------------------------- --}}
    <div class="fin-tab-panel is-active" data-fin-panel="entradas">
        @if ($entries->isEmpty())
            <p class="sales-empty">Nenhuma entrada neste mês.</p>
        @else
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Descrição</th>
                        <th>Forma</th>
                        <th>Status</th>
                        <th>Valor</th>
                        <th>Pago</th>
                        <th>Saldo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ $entry['date']?->format('d/m/Y') ?? '—' }}</td>
                            <td><strong>{{ $entry['client'] }}</strong></td>
                            <td>
                                @if ($entry['invoice'])
                                    <a href="{{ route('finance.invoices.show', $entry['invoice']) }}"><strong>{{ $entry['reference'] }}</strong></a>
                                    <div class="sales-sub">{{ \Illuminate\Support\Str::limit($entry['description'], 90) }}</div>
                                @else
                                    <strong>{{ $entry['reference'] }}</strong>
                                    <div class="sales-sub">{{ $entry['description'] }}</div>
                                @endif
                            </td>
                            <td>{{ $entry['method'] }}</td>
                            <td><span class="fin-badge fin-badge--{{ $entry['tone'] }}">{{ $entry['status'] }}</span></td>
                            <td><x-money :cents="$entry['amount_cents']" /></td>
                            <td><x-money :cents="$entry['paid_cents']" /></td>
                            <td><x-money :cents="$entry['balance_cents']" /></td>
                            <td>
                                <div class="fin-actions">
                                    @if ($entry['invoice'])
                                        @if ($entry['is_draft'])
                                            <form method="post" action="{{ route('finance.invoices.approve', $entry['invoice']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn--small" onclick="return confirm('Aprovar e enviar esta fatura pelo WhatsApp?');">Aprovar</button>
                                            </form>
                                            <a href="{{ route('finance.invoices.edit', $entry['invoice']) }}" class="btn btn--ghost btn--small">Editar</a>
                                        @elseif ($entry['can_remind'])
                                            <form method="post" action="{{ route('finance.invoices.remind', $entry['invoice']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn--small" onclick="return confirm('Enviar cobrança de atraso?');">Cobrar atraso</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('finance.invoices.show', $entry['invoice']) }}" class="btn btn--ghost btn--small">Abrir</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5"><strong>Total</strong></td>
                        <td><strong><x-money :cents="$entries->sum('amount_cents')" /></strong></td>
                        <td><strong><x-money :cents="$entries->sum('paid_cents')" /></strong></td>
                        <td><strong><x-money :cents="$entries->sum('balance_cents')" /></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif

        <p class="fin-note" style="margin-top:12px;">
            Entradas = cobranças PIX + recebimentos de cartão/manuais sem cobrança. Pagamentos vinculados a uma cobrança já aparecem na coluna <strong>Pago</strong>.
        </p>
    </div>

    {{-- SAÍDAS ----------------------------------------------------------- --}}
    <div class="fin-tab-panel" data-fin-panel="saidas">
        <div class="fin-actions" style="margin-bottom:12px;">
            <form method="post" action="{{ route('finance.expenses.copy.store') }}">
                @csrf
                <input type="hidden" name="source" value="{{ $month->copy()->subMonthNoOverflow()->format('Y-m') }}">
                <input type="hidden" name="target" value="{{ $month->format('Y-m') }}">
                <input type="hidden" name="redirect" value="month">
                <button type="submit" class="btn btn--ghost btn--small">Repetir mês anterior</button>
            </form>
            <form method="post" action="{{ route('finance.expenses.generate-recurring') }}">
                @csrf
                <button type="submit" class="btn btn--ghost btn--small">Gerar recorrentes</button>
            </form>
        </div>

        @if ($expenses->isEmpty())
            <p class="sales-empty">Nenhuma despesa neste mês.</p>
        @else
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Vencimento</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Pago por</th>
                        <th>Status</th>
                        <th>Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expenses as $expense)
                        <tr>
                            <td>
                                {{ $expense->due_date?->format('d/m/Y') ?? '—' }}
                                @if ($expense->isOverdue())
                                    <div class="sales-sub fin-tone-danger">vencida</div>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('finance.expenses.edit', $expense) }}"><strong>{{ $expense->description }}</strong></a>
                                @if ($expense->notes)
                                    <div class="sales-sub">{{ \Illuminate\Support\Str::limit($expense->notes, 90) }}</div>
                                @endif
                            </td>
                            <td>{{ $expense->categoryLabel() }}</td>
                            <td>
                                {{ $expense->payerLabel() }}
                                @if ($expense->paid_by_partner_id)
                                    <div class="sales-sub {{ $expense->reimbursed_at ? 'fin-tone-ok' : 'fin-tone-pending' }}">
                                        {{ $expense->reimbursed_at ? 'reembolsado' : 'a reembolsar' }}
                                    </div>
                                @endif
                            </td>
                            <td><span class="fin-badge fin-badge--{{ $expense->statusTone() }}">{{ $expense->statusLabel() }}</span></td>
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
                <tfoot>
                    <tr>
                        <td colspan="5"><strong>Total</strong></td>
                        <td><strong><x-money :cents="$expenses->sum('amount_cents')" /></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- DISTRIBUIÇÃO ----------------------------------------------------- --}}
    <div class="fin-tab-panel" data-fin-panel="distribuicao">
        @if ($partners->isEmpty())
            <p class="sales-empty">Cadastre os sócios em <a href="{{ route('finance.settings') }}">Configuração financeira</a> para distribuir o lucro.</p>
        @else
            <div class="fin-grid" style="margin-bottom:16px;">
                <div class="fin-card">
                    <div class="label">Lucro distribuível</div>
                    <div class="value {{ $distributable > 0 ? 'fin-tone-ok' : 'fin-tone-muted' }}"><x-money :cents="$distributable" /></div>
                </div>
                <div class="fin-card">
                    <div class="label">Distribuído</div>
                    <div class="value"><x-money :cents="$summary['distributed_profit_cents']" /></div>
                </div>
                <div class="fin-card">
                    <div class="label">Reembolsos</div>
                    <div class="value fin-tone-pending"><x-money :cents="$summary['distributed_reimbursement_cents']" /></div>
                </div>
                <div class="fin-card">
                    <div class="label">Guardado</div>
                    <div class="value {{ $summary['saved_cents'] >= 0 ? 'fin-tone-ok' : 'fin-tone-danger' }}"><x-money :cents="$summary['saved_cents']" /></div>
                </div>
            </div>

            <form method="post" action="{{ route('finance.months.distribution') }}" id="distributionForm">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">

                @foreach ($partners as $partner)
                    @php
                        $profitRow = $profitRows->get($partner->id);
                        $reimbursementRow = $reimbursementRows->get($partner->id);
                        $profitValue = $profitRow
                            ? $profitRow->amount_cents / 100
                            : round($distributable * $partner->share_bps / 10000 / 100, 2);
                        $partnerExpenses = $unreimbursed->get($partner->id, collect());
                        $reimbursementValue = $partnerExpenses->sum('amount_cents') / 100;
                    @endphp

                    <div class="fin-partner" data-partner-row>
                        <div class="fin-partner__head">
                            <div>
                                <div class="fin-partner__name">{{ $partner->name }}</div>
                                <div class="fin-mini">Participação: {{ $partner->shareLabel() }}</div>
                            </div>
                            <div class="fin-partner__total">
                                Total: <strong data-partner-total>R$ {{ number_format($profitValue + $reimbursementValue, 2, ',', '.') }}</strong>
                            </div>
                        </div>

                        <div class="sales-form" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <label>
                                Lucro (R$)
                                <input type="number" step="0.01" min="0" name="distributions[{{ $partner->id }}][profit]" value="{{ number_format($profitValue, 2, '.', '') }}" data-profit-input>
                            </label>
                            <label>
                                Reembolso (R$)
                                <input type="text" value="{{ number_format($reimbursementValue, 2, ',', '.') }}" data-reimbursement-display readonly>
                            </label>
                        </div>

                        <div style="margin-top:12px;">
                            <div class="fin-mini" style="margin-bottom:4px;">Despesas pagas por {{ $partner->name }} a reembolsar</div>
                            @if ($partnerExpenses->isEmpty())
                                <p class="fin-mini">Nenhuma despesa pendente de reembolso.</p>
                            @else
                                @foreach ($partnerExpenses as $expense)
                                    <div class="fin-expense-pick">
                                        <label>
                                            <input type="checkbox" name="distributions[{{ $partner->id }}][expense_ids][]" value="{{ $expense->id }}" checked data-expense-checkbox data-amount="{{ $expense->amount_cents }}">
                                            <span>
                                                {{ $expense->description }}
                                                <span class="fin-mini">· {{ $expense->paid_at?->format('d/m/Y') ?? $expense->due_date?->format('d/m/Y') }}</span>
                                            </span>
                                        </label>
                                        <span><x-money :cents="$expense->amount_cents" /></span>
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        @if ($reimbursementRow)
                            <p class="fin-mini" style="margin-top:8px;">Reembolso registrado em {{ $reimbursementRow->paid_at?->format('d/m/Y') ?? '—' }}.</p>
                        @endif
                    </div>
                @endforeach

                <div class="fin-actions">
                    <button type="submit" class="btn">Salvar distribuição</button>
                </div>
            </form>

            @if ($distributions->isNotEmpty())
                <h2 style="margin:24px 0 10px; font-size:1rem;">Histórico do mês</h2>
                <table class="sales-table">
                    <thead><tr><th>Sócio</th><th>Tipo</th><th>Pago em</th><th>Valor</th></tr></thead>
                    <tbody>
                        @foreach ($distributions->sortBy('partner.name') as $distribution)
                            <tr>
                                <td>{{ $distribution->partner?->name ?? '—' }}</td>
                                <td>{{ $distribution->kindLabel() }}</td>
                                <td>{{ $distribution->paid_at?->format('d/m/Y') ?? '—' }}</td>
                                <td><x-money :cents="$distribution->amount_cents" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>
</div>

<script>
    (function () {
        const tabs = document.querySelectorAll('[data-fin-tab]');
        const panels = document.querySelectorAll('[data-fin-panel]');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((t) => t.classList.remove('is-active'));
                panels.forEach((p) => p.classList.remove('is-active'));
                tab.classList.add('is-active');
                document.querySelector('[data-fin-panel="' + tab.dataset.finTab + '"]')?.classList.add('is-active');
            });
        });

        function money(cents) {
            return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function refresh(row) {
            const input = row.querySelector('[data-profit-input]');
            const total = row.querySelector('[data-partner-total]');
            const reimbursement = row.querySelector('[data-reimbursement-display]');
            let cents = Math.round((parseFloat(input?.value || '0') || 0) * 100);
            let reimbursementCents = 0;

            row.querySelectorAll('[data-expense-checkbox]').forEach((checkbox) => {
                if (checkbox.checked) {
                    reimbursementCents += parseInt(checkbox.dataset.amount || '0', 10);
                }
            });

            if (reimbursement) reimbursement.value = (reimbursementCents / 100).toFixed(2).replace('.', ',');
            if (total) total.textContent = money(cents + reimbursementCents);
        }

        document.querySelectorAll('[data-partner-row]').forEach((row) => {
            row.querySelector('[data-profit-input]')?.addEventListener('input', () => refresh(row));
            row.querySelectorAll('[data-expense-checkbox]').forEach((checkbox) => {
                checkbox.addEventListener('change', () => refresh(row));
            });
            refresh(row);
        });
    })();
</script>
@endsection
