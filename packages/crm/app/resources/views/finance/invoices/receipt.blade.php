@extends('layouts.print')

@section('title', 'Recibo '.$invoice->number)

@section('content')
@php
    $paid = $invoice->isPaid();
    $issuer = $setting->legal_name ?: 'VAIF';
@endphp

<div class="row" style="align-items:flex-start; margin-bottom:24px;">
    <div>
        <h1>{{ $issuer }}</h1>
        <div class="muted">
            @if ($setting->tax_id) CNPJ {{ $setting->tax_id }}<br>@endif
            @if ($setting->address) {!! nl2br(e($setting->address)) !!}<br>@endif
            @if ($setting->pix_key) PIX: {{ $setting->pix_key }}@endif
        </div>
    </div>
    <div class="right">
        <div class="badge {{ $paid ? 'badge--paid' : ($invoice->isOverdue() ? 'badge--overdue' : '') }}">{{ $invoice->statusLabel() }}</div>
        <div style="margin-top:8px; font-size:16px; font-weight:600;">{{ $invoice->number }}</div>
        <div class="muted">Emissão {{ $invoice->issue_date?->format('d/m/Y') }}</div>
        <div class="muted">Vencimento {{ $invoice->due_date?->format('d/m/Y') }}</div>
    </div>
</div>

<div class="box" style="margin-bottom:20px;">
    <div class="row">
        <div>
            <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.6px;">Cliente</div>
            <strong>{{ $invoice->prospect?->name ?? '—' }}</strong>
            <div class="muted">{{ $invoice->prospect?->displayPhone() }}</div>
            @if ($invoice->prospect?->email)
                <div class="muted">{{ $invoice->prospect->email }}</div>
            @endif
        </div>
        <div class="right">
            <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.6px;">Período</div>
            <div>{{ $invoice->periodLabel() }}</div>
            <div class="muted">{{ $invoice->paymentMethodLabel() }}</div>
        </div>
    </div>
</div>

<h2>Itens</h2>
<table>
    <thead>
        <tr>
            <th>Descrição</th>
            <th class="right">Qtd.</th>
            <th class="right">Unitário</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td class="right">R$ {{ number_format($item->unit_amount_cents / 100, 2, ',', '.') }}</td>
                <td class="right">R$ {{ number_format($item->total_cents / 100, 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="row" style="margin-top:18px; justify-content:flex-end;">
    <div style="min-width:260px;">
        <div class="row"><span class="muted">Total</span><strong>R$ {{ number_format($invoice->amount_cents / 100, 2, ',', '.') }}</strong></div>
        <div class="row"><span class="muted">Pago</span><span>R$ {{ number_format($invoice->paid_cents / 100, 2, ',', '.') }}</span></div>
        <div class="row" style="margin-top:6px; padding-top:6px; border-top:1px solid #ddd;">
            <span class="muted">Saldo</span><span class="total">R$ {{ number_format($invoice->balanceCents() / 100, 2, ',', '.') }}</span>
        </div>
    </div>
</div>

@if ($invoice->payments->isNotEmpty())
    <h2>Pagamentos</h2>
    <table>
        <thead><tr><th>Data</th><th>Método</th><th>Referência</th><th class="right">Valor</th></tr></thead>
        <tbody>
            @foreach ($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->paid_at?->format('d/m/Y') }}</td>
                    <td>{{ $payment->methodLabel() }}</td>
                    <td>{{ $payment->external_ref ?: ($payment->reference ?: '—') }}</td>
                    <td class="right">R$ {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($setting->payment_instructions)
    <div class="box" style="margin-top:22px;">
        <strong>Instruções de pagamento</strong>
        <div class="muted" style="margin-top:6px;">{!! nl2br(e($setting->payment_instructions)) !!}</div>
    </div>
@endif

@if ($invoice->notes)
    <div class="footer">
        <strong>Observações:</strong> {{ $invoice->notes }}
    </div>
@endif

<div class="footer">
    {{ $setting->receipt_footer ?: 'Recibo simples de cobrança. Não substitui nota fiscal.' }}
</div>
@endsection
