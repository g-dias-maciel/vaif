@extends('layouts.app')

@section('title', 'Configuração financeira')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Configuração financeira</h1>
            <p class="meta" style="margin-top:6px;">Dados do emissor, PIX e instruções usadas nas cobranças e recibos.</p>
        </div>
        <a href="{{ route('finance.dashboard') }}" class="btn btn--ghost btn--small">Voltar</a>
    </div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
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

    <form method="post" action="{{ route('finance.settings.update') }}" class="sales-form">
        @csrf
        @method('put')

        <label>
            Razão social / nome
            <input type="text" name="legal_name" value="{{ old('legal_name', $setting->legal_name) }}">
        </label>

        <label>
            CNPJ
            <input type="text" name="tax_id" value="{{ old('tax_id', $setting->tax_id) }}">
        </label>

        <label>
            Prefixo da cobrança
            <input type="text" name="invoice_prefix" value="{{ old('invoice_prefix', $setting->invoice_prefix) }}" required>
        </label>

        <label>
            Dia de vencimento padrão
            <input type="number" min="1" max="31" name="default_due_day" value="{{ old('default_due_day', $setting->default_due_day) }}" required>
        </label>

        <label class="full">
            Endereço
            <textarea name="address">{{ old('address', $setting->address) }}</textarea>
        </label>

        <label class="full">
            Chave PIX
            <input type="text" name="pix_key" value="{{ old('pix_key', $setting->pix_key) }}">
        </label>

        <label class="full">
            Instruções de pagamento
            <textarea name="payment_instructions">{{ old('payment_instructions', $setting->payment_instructions) }}</textarea>
        </label>

        <label class="full">
            Rodapé do recibo
            <textarea name="receipt_footer">{{ old('receipt_footer', $setting->receipt_footer) }}</textarea>
        </label>

        <div class="full">
            <p class="fin-note" style="margin-bottom:10px;">
                Este é um recibo simples de cobrança; não emite NF-e/NFS-e. A cobrança PIX é gerada pelo comando diário
                <code>finance:generate-invoices</code> e os atrasos por <code>finance:mark-overdue</code>.
            </p>
            <button type="submit" class="btn">Salvar configuração</button>
        </div>
    </form>
</div>
@endsection
