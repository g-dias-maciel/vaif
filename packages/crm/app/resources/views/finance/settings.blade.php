@extends('layouts.app')

@section('title', 'Configuração financeira')

@section('content')
@include('finance.partials.styles')
@include('finance.partials.nav', ['active' => 'settings'])
@include('finance.partials.breadcrumbs', ['items' => [['label' => 'Configuração', 'url' => null]]])

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Configuração financeira</h1>
            <p class="meta" style="margin-top:6px;">Emissor, PIX, textos da fatura, aprovação e sócios.</p>
        </div>
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
            Antecedência da cobrança (dias)
            <input type="number" min="0" max="30" name="invoice_lead_days" value="{{ old('invoice_lead_days', $setting->invoice_lead_days ?? 3) }}" required>
        </label>

        <label class="full">
            E-mail para aprovação de faturas
            <input type="text" name="invoice_approval_email" value="{{ old('invoice_approval_email', $setting->invoice_approval_email) }}" placeholder="financeiro@vaif.com.br (vazio usa o e-mail admin)">
        </label>

        <label class="full">
            Endereço do emissor
            <textarea name="address">{{ old('address', $setting->address) }}</textarea>
        </label>

        <label class="full">
            Chave PIX
            <input type="text" name="pix_key" value="{{ old('pix_key', $setting->pix_key) }}">
        </label>

        <label class="full">
            Termos da fatura
            <textarea name="invoice_terms" rows="3">{{ old('invoice_terms', $setting->invoice_terms ?: \App\Models\BillingSetting::DEFAULT_INVOICE_TERMS) }}</textarea>
        </label>

        <label class="full">
            Mensagem de envio da fatura (WhatsApp)
            <textarea name="invoice_whatsapp_message" rows="6">{{ old('invoice_whatsapp_message', $setting->invoice_whatsapp_message ?: \App\Models\BillingSetting::DEFAULT_INVOICE_MESSAGE) }}</textarea>
            <span class="fin-mini">Variáveis: {primeiro_nome}, {cliente}, {numero}, {periodo}, {vencimento}, {valor}, {link}, {notas}, {pix}</span>
        </label>

        <label class="full">
            Mensagem de cobrança de atraso (WhatsApp)
            <textarea name="dunning_whatsapp_message" rows="4">{{ old('dunning_whatsapp_message', $setting->dunning_whatsapp_message ?: \App\Models\BillingSetting::DEFAULT_DUNNING_MESSAGE) }}</textarea>
            <span class="fin-mini">Usa as mesmas variáveis da mensagem de envio.</span>
        </label>

        <label class="full">
            Instruções de pagamento (recibo)
            <textarea name="payment_instructions">{{ old('payment_instructions', $setting->payment_instructions) }}</textarea>
        </label>

        <label class="full">
            Rodapé do recibo
            <textarea name="receipt_footer">{{ old('receipt_footer', $setting->receipt_footer) }}</textarea>
        </label>

        <div class="full">
            <p class="fin-note" style="margin-bottom:10px;">
                A fatura é gerada em PDF e enviada pelo WhatsApp da sessão financeira. A aprovação vai para o e-mail acima
                (ou para o admin quando vazio). Não há integração com Mercado Pago; o cartão é registrado como recebimento.
            </p>
            <button type="submit" class="btn">Salvar configuração</button>
        </div>
    </form>

    <div class="fin-section">
        <h2>Sócios</h2>
        <p class="fin-note" style="margin-bottom:12px;">
            A participação é usada para sugerir a divisão do lucro. Despesas pagas por um sócio entram como reembolso na
            distribuição do mês.
        </p>

        @if ($partners->isEmpty())
            <p class="sales-empty">Nenhum sócio cadastrado.</p>
        @else
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Participação</th>
                        <th>Ativo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($partners as $partner)
                        <tr>
                            <td colspan="4" style="padding:0;">
                                <form method="post" action="{{ route('finance.partners.update', $partner) }}" class="sales-form" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end;padding:10px 6px;">
                                    @csrf
                                    @method('put')
                                    <label>
                                        Nome
                                        <input type="text" name="name" value="{{ $partner->name }}" required>
                                    </label>
                                    <label>
                                        Participação (%)
                                        <input type="number" step="0.01" min="0" max="100" name="share_percent" value="{{ number_format($partner->sharePercent(), 2, '.', '') }}" required>
                                    </label>
                                    <label class="sales-check" style="margin-top:18px;">
                                        <input type="checkbox" name="active" value="1" @checked($partner->active)>
                                        Ativo
                                    </label>
                                    <div class="fin-actions">
                                        <button type="submit" class="btn btn--small">Salvar</button>
                                    </div>
                                </form>
                                @if (! $partner->distributions()->exists())
                                    <form method="post" action="{{ route('finance.partners.destroy', $partner) }}" onsubmit="return confirm('Remover este sócio?');" style="padding:0 6px 10px;">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="btn btn--ghost btn--small">Remover</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h3 style="margin:22px 0 10px;font-size:.9rem;">Adicionar sócio</h3>
        <form method="post" action="{{ route('finance.partners.store') }}" class="sales-form">
            @csrf
            <label>
                Nome
                <input type="text" name="name" value="{{ old('name') }}" required>
            </label>
            <label>
                Participação (%)
                <input type="number" step="0.01" min="0" max="100" name="share_percent" value="{{ old('share_percent', 50) }}" required>
            </label>
            <label class="sales-check">
                <input type="checkbox" name="active" value="1" checked>
                Ativo
            </label>
            <div class="full">
                <button type="submit" class="btn btn--ghost">Adicionar sócio</button>
            </div>
        </form>
    </div>
</div>
@endsection
