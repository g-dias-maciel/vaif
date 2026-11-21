@extends('layouts.app')

@section('title', 'Cobrança '.$invoice->number)

@section('content')
@include('finance.partials.styles')
@php
    $backUrl = \App\Support\FinanceNavigation::backUrl(request('return_to'), route('finance.invoices.index'));
    $navMonth = $invoice->period_start ?? now();
@endphp
@include('finance.partials.nav', ['active' => 'invoices', 'navMonth' => $navMonth, 'backUrl' => $backUrl])
@include('finance.partials.breadcrumbs', ['items' => [
    ['label' => $navMonth->format('m/Y'), 'url' => route('finance.months', ['month' => $navMonth->format('Y-m')])],
    ['label' => 'Cobranças', 'url' => route('finance.invoices.index')],
    ['label' => $invoice->number, 'url' => null],
]])

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $invoice->number }}</h1>
            <p class="meta" style="margin-top:6px;">
                {{ $invoice->prospect?->name ?? '—' }} · {{ $invoice->periodLabel() }}
            </p>
        </div>
        <div class="fin-actions">
            @if (! $invoice->isPaid() && ! $invoice->isCancelled())
                <a href="{{ route('finance.invoices.edit', ['invoice' => $invoice, 'return_to' => url()->full()]) }}" class="btn btn--ghost btn--small">Editar</a>
            @endif
            @if (! $invoice->isDraft() && ! $invoice->isPaid() && ! $invoice->isCancelled())
                <form method="post" action="{{ route('finance.invoices.resend', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn--ghost btn--small">Reenviar fatura</button>
                </form>
            @endif
            <a href="{{ route('finance.invoices.pdf', $invoice) }}" class="btn btn--ghost btn--small">Baixar PDF</a>
            <a href="{{ route('finance.invoices.receipt', $invoice) }}" class="btn btn--ghost btn--small" target="_blank">Recibo</a>
            <a href="{{ $backUrl }}" class="btn btn--ghost btn--small">Voltar</a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

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

    @if ($invoice->isDraft())
        <div class="flash" style="background:#2a1f0a;border-color:#fbbf24;color:#fde68a;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span>Esta fatura está aguardando aprovação. Revise os itens e o vencimento antes de enviar.</span>
            <form method="post" action="{{ route('finance.invoices.approve', $invoice) }}">
                @csrf
                <input type="hidden" name="return_to" value="{{ request('return_to') }}">
                <button type="submit" class="btn btn--small" onclick="return confirm('Aprovar e enviar esta fatura pelo WhatsApp?');">Aprovar e enviar</button>
            </form>
        </div>
    @elseif ($invoice->delivery_status === \App\Models\BillingInvoice::DELIVERY_FAILED)
        <div class="flash" style="background:#2a0a0a;border-color:var(--danger);color:#fecaca;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span>Falha no envio: {{ $invoice->delivery_error ?: 'erro desconhecido' }}.</span>
            <form method="post" action="{{ route('finance.invoices.resend', $invoice) }}">
                @csrf
                <input type="hidden" name="return_to" value="{{ request('return_to') }}">
                <button type="submit" class="btn btn--ghost btn--small">Tentar novamente</button>
            </form>
        </div>
    @endif

    @if ($invoice->isOverdue())
        <div class="flash" style="background:#2a0a0a;border-color:var(--danger);color:#fecaca;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span>Fatura atrasada. {{ $invoice->reminder_count > 0 ? $invoice->reminder_count.' lembrete(s) enviado(s).' : 'Nenhum lembrete enviado ainda.' }}</span>
            <form method="post" action="{{ route('finance.invoices.remind', $invoice) }}">
                @csrf
                <input type="hidden" name="return_to" value="{{ request('return_to') }}">
                <button type="submit" class="btn btn--ghost btn--small" onclick="return confirm('Enviar cobrança de atraso pelo WhatsApp?');">Cobrar atraso</button>
            </form>
        </div>
    @endif

    <div class="fin-grid" style="margin-bottom:20px;">
        <div class="fin-card">
            <div class="label">Status</div>
            <div class="value"><span class="fin-badge fin-badge--{{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></div>
            @if ($invoice->isOverdue())
                <div class="hint fin-tone-danger">Vencida em {{ $invoice->due_date?->format('d/m/Y') }}</div>
            @else
                <div class="hint">Vence em {{ $invoice->due_date?->format('d/m/Y') }}</div>
            @endif
        </div>
        <div class="fin-card">
            <div class="label">Valor</div>
            <div class="value"><x-money :cents="$invoice->amount_cents" /></div>
            <div class="hint">{{ $invoice->paymentMethodLabel() }}</div>
        </div>
        <div class="fin-card">
            <div class="label">Pago</div>
            <div class="value fin-tone-ok"><x-money :cents="$invoice->paid_cents" /></div>
            <div class="hint">{{ $invoice->payments->count() }} pagamento(s)</div>
        </div>
        <div class="fin-card">
            <div class="label">Saldo</div>
            <div class="value {{ $invoice->balanceCents() > 0 ? 'fin-tone-pending' : 'fin-tone-ok' }}"><x-money :cents="$invoice->balanceCents()" /></div>
            <div class="hint">Emissão {{ $invoice->issue_date?->format('d/m/Y') }}</div>
        </div>
        <div class="fin-card">
            <div class="label">Envio</div>
            <div class="value">{{ $invoice->deliveryLabel() }}</div>
            <div class="hint">
                @if ($invoice->sent_at)
                    {{ $invoice->sent_at->format('d/m/Y H:i') }}
                @else
                    Ainda não enviada
                @endif
            </div>
        </div>
        <div class="fin-card">
            <div class="label">Aprovação</div>
            <div class="value">{{ $invoice->approved_at ? $invoice->approved_at->format('d/m/Y') : '—' }}</div>
            <div class="hint">{{ $invoice->approver?->name ?? '—' }}</div>
        </div>
    </div>

    @if ($invoice->external_ref || $invoice->pix_copy_paste)
        <div class="flash" style="background:#0a1f2a;border-color:#60a5fa;color:#93c5fd;">
            @if ($invoice->external_ref)
                <div>Referência externa: <code>{{ $invoice->external_ref }}</code></div>
            @endif
            @if ($invoice->pix_copy_paste)
                <div style="margin-top:8px;">PIX copia e cola:
                    <textarea readonly style="width:100%;margin-top:6px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--ink);padding:9px 12px;font-size:.78rem;" rows="3">{{ $invoice->pix_copy_paste }}</textarea>
                </div>
            @endif
        </div>
    @endif

    <div class="fin-section">
        <h2>Itens</h2>
        <table class="sales-table">
            <thead><tr><th>Descrição</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr></thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                        <td><x-money :cents="$item->unit_amount_cents" /></td>
                        <td><x-money :cents="$item->total_cents" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">Subtotal</td>
                    <td><strong><x-money :cents="$invoice->subtotalCents()" /></strong></td>
                </tr>
                @if ($invoice->discountCents() > 0)
                    <tr>
                        <td colspan="3" style="text-align:right;">Desconto</td>
                        <td>- <x-money :cents="$invoice->discountCents()" /></td>
                    </tr>
                @endif
                <tr>
                    <td colspan="3" style="text-align:right;"><strong>Total</strong></td>
                    <td><strong><x-money :cents="$invoice->amount_cents" /></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="fin-section">
        <h2>Pagamentos</h2>
        @if ($invoice->payments->isEmpty())
            <p class="sales-empty">Nenhum pagamento registrado.</p>
        @else
            <table class="sales-table">
                <thead><tr><th>Data</th><th>Método</th><th>Origem</th><th>Referência</th><th>Valor</th><th>Registrado por</th></tr></thead>
                <tbody>
                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at?->format('d/m/Y') }}</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td>{{ $payment->sourceLabel() }}</td>
                            <td>{{ $payment->external_ref ?: ($payment->reference ?: '—') }}</td>
                            <td><x-money :cents="$payment->amount_cents" /></td>
                            <td>{{ $payment->recorder?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if (! $invoice->isPaid() && ! $invoice->isCancelled() && ! $invoice->isDraft())
        <div class="fin-section" style="display:grid; grid-template-columns:1fr 1fr; gap:22px;">
            <div>
                <h2>Registrar pagamento</h2>
                <form method="post" action="{{ route('finance.payments.store') }}" class="sales-form">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ url()->full() }}">
                    <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                    <input type="hidden" name="contract_id" value="{{ $invoice->contract_id }}">

                    <label>
                        Valor (R$)
                        <input type="number" step="0.01" min="0.01" name="amount" value="{{ number_format($invoice->balanceCents() / 100, 2, '.', '') }}">
                    </label>

                    <label>
                        Data
                        <input type="date" name="paid_at" value="{{ now()->toDateString() }}">
                    </label>

                    <label>
                        Método
                        <select name="method">
                            @foreach (['pix' => 'PIX', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                                <option value="{{ $value }}" @selected($invoice->payment_method === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        Referência
                        <input type="text" name="reference" placeholder="Comprovante / observação">
                    </label>

                    <label>
                        ID externo
                        <input type="text" name="external_ref" placeholder="ID Mercado Pago / n8n">
                    </label>

                    <label>
                        Origem
                        <select name="source">
                            <option value="manual">Manual</option>
                            <option value="mercado_pago">Mercado Pago</option>
                            <option value="n8n">n8n</option>
                        </select>
                    </label>

                    <label class="full">
                        Observações
                        <textarea name="notes"></textarea>
                    </label>

                    <div class="full">
                        <button type="submit" class="btn">Registrar pagamento</button>
                    </div>
                </form>
            </div>

            <div>
                <h2>Atalhos</h2>
                <p class="fin-note" style="margin-bottom:12px;">Recebe o saldo restante de uma vez e marca a cobrança como paga.</p>
                <form method="post" action="{{ route('finance.payments.store') }}" style="margin-bottom:16px;">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ url()->full() }}">
                    <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                    <input type="hidden" name="contract_id" value="{{ $invoice->contract_id }}">
                    <input type="hidden" name="amount" value="{{ number_format($invoice->balanceCents() / 100, 2, '.', '') }}">
                    <input type="hidden" name="paid_at" value="{{ now()->toDateString() }}">
                    <input type="hidden" name="method" value="{{ $invoice->payment_method }}">
                    <button type="submit" class="btn">Receber saldo (<x-money :cents="$invoice->balanceCents()" />)</button>
                </form>

                @if ($invoice->paid_cents === 0)
                    <form method="post" action="{{ route('finance.invoices.cancel', $invoice) }}" onsubmit="return confirm('Cancelar esta cobrança?');">
                        @csrf
                        <input type="hidden" name="return_to" value="{{ request('return_to') }}">
                        <button type="submit" class="btn btn--danger">Cancelar cobrança</button>
                    </form>
                @endif

                @if ($invoice->contract)
                    <p class="fin-note" style="margin-top:16px;">
                        Contrato: <a href="{{ route('finance.contracts.edit', ['contract' => $invoice->contract, 'return_to' => url()->full()]) }}">{{ $invoice->contract->planLabel() }}</a>
                        · {{ $invoice->contract->billingModeLabel() }}
                    </p>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
