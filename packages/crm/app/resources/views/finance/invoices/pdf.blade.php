<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Fatura {{ $invoice->number }}</title>
    <style>
        @page { margin: 42px 44px 36px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            color: #1a1a1a;
            line-height: 1.45;
        }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
        .header td { vertical-align: top; }
        .logo { width: 190px; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 27px; letter-spacing: 1.5px; font-weight: 700; }
        .title .number { margin-top: 4px; font-size: 12px; color: #555; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .meta td { width: 33.33%; padding: 10px 12px; border: 1px solid #e3e3e3; vertical-align: top; }
        .meta .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #777; margin-bottom: 3px; }
        .meta .value { font-size: 12px; font-weight: 600; }
        .issuer { margin-bottom: 22px; }
        .issuer .name { font-size: 12px; font-weight: 700; }
        .muted { color: #666; }
        .section-title { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #777; margin-bottom: 4px; }
        .bill-to { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .bill-to td { width: 50%; vertical-align: top; padding-right: 20px; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .items th { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #777; text-align: left; padding: 8px 6px; border-bottom: 1px solid #cfcfcf; }
        .items th.right, .items td.right { text-align: right; }
        .items td { padding: 9px 6px; border-bottom: 1px solid #ececec; vertical-align: top; }
        .items .description { width: 74%; white-space: pre-line; }
        .items .price { width: 26%; white-space: nowrap; }
        .terms { border: 1px solid #e3e3e3; background: #fafafa; padding: 12px 14px; margin: 18px 0; font-size: 9.5px; }
        .terms strong { display: block; margin-bottom: 5px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #777; }
        .totals { width: 45%; margin-left: 55%; border-collapse: collapse; }
        .totals td { padding: 5px 6px; }
        .totals .label { color: #666; }
        .totals .right { text-align: right; white-space: nowrap; }
        .totals .grand td { border-top: 1px solid #cfcfcf; padding-top: 9px; font-size: 13px; font-weight: 700; }
        .notes { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e3e3e3; }
        .notes .body { white-space: pre-line; color: #333; }
        .footer { margin-top: 26px; font-size: 8.5px; color: #888; text-align: center; }
        .badge { display: inline-block; padding: 2px 7px; border: 1px solid #999; border-radius: 10px; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; }
    </style>
</head>
<body>
@php
    $issuer = $setting->legal_name ?: 'VAIF Marketing';
    $money = fn (int $cents): string => 'R$ '.number_format($cents / 100, 2, ',', '.');
    $items = $invoice->items->isNotEmpty()
        ? $invoice->items
        : collect([(object) [
            'description' => 'Cobrança '.$invoice->number,
            'total_cents' => $invoice->subtotalCents(),
        ]]);
    $subtotal = $invoice->subtotalCents();
    $discount = $invoice->discountCents();
    $terms = $setting->invoice_terms ?: \App\Models\BillingSetting::DEFAULT_INVOICE_TERMS;
@endphp

<table class="header">
    <tr>
        <td>
            @if (! empty($logoData))
                <img src="data:image/png;base64,{{ $logoData }}" class="logo" alt="{{ $issuer }}">
            @else
                <div style="font-size: 22px; font-weight: 700;">{{ $issuer }}</div>
            @endif
        </td>
        <td class="title">
            <h1>INVOICE</h1>
            <div class="number">#{{ $invoice->number }}</div>
            @if ($invoice->isPaid())
                <div style="margin-top: 8px;"><span class="badge">Paga</span></div>
            @elseif ($invoice->isOverdue())
                <div style="margin-top: 8px;"><span class="badge">Atrasada</span></div>
            @endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td>
            <div class="label">Emissão</div>
            <div class="value">{{ $invoice->issue_date?->format('d/m/Y') ?? '—' }}</div>
        </td>
        <td>
            <div class="label">Vencimento</div>
            <div class="value">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</div>
        </td>
        <td>
            <div class="label">Valor em aberto</div>
            <div class="value">{{ $money($invoice->balanceCents()) }}</div>
        </td>
    </tr>
</table>

<div class="issuer">
    <div class="name">{{ $issuer }}</div>
    <div class="muted">
        @if ($setting->tax_id)
            CNPJ {{ $setting->tax_id }}<br>
        @endif
        @if ($setting->address)
            {!! nl2br(e($setting->address)) !!}
        @endif
    </div>
</div>

<table class="bill-to">
    <tr>
        <td>
            <div class="section-title">Cobrança para</div>
            <div><strong>{{ $invoice->prospect?->name ?? '—' }}</strong></div>
            <div class="muted">{{ $invoice->prospect?->displayPhone() }}</div>
            @if ($invoice->prospect?->email)
                <div class="muted">{{ $invoice->prospect->email }}</div>
            @endif
        </td>
        <td>
            <div class="section-title">Endereço</div>
            <div class="muted">
                {{ $invoice->prospect?->billing_address ?: ($invoice->prospect?->city ?: '—') }}
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th class="description">Descrição</th>
            <th class="price right">Valor</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td class="description">{{ $item->description }}</td>
                <td class="price right">{{ $money((int) $item->total_cents) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="terms">
    <strong>Termos</strong>
    @if ($setting->pix_key)
        <div>Pagamentos devem ser realizados através da chave PIX: <strong>{{ $setting->pix_key }}</strong></div>
    @endif
    @if ($terms)
        <div style="margin-top: 5px;">{{ $terms }}</div>
    @endif
</div>

<table class="totals">
    <tr>
        <td class="label">Subtotal:</td>
        <td class="right">{{ $money($subtotal) }}</td>
    </tr>
    <tr>
        <td class="label">Desconto:</td>
        <td class="right">- {{ $money($discount) }}</td>
    </tr>
    <tr class="grand">
        <td>Total:</td>
        <td class="right">{{ $money($invoice->amount_cents) }}</td>
    </tr>
</table>

@if ($invoice->notes)
    <div class="notes">
        <div class="section-title">Notas</div>
        <div class="body">{{ $invoice->notes }}</div>
    </div>
@endif

@if ($setting->receipt_footer)
    <div class="footer">{{ $setting->receipt_footer }}</div>
@endif
</body>
</html>
