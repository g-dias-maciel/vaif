@extends('layouts.app')

@section('title', 'Clientes e contratos')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Clientes e contratos</h1>
            <p class="meta" style="margin-top:6px;">{{ $prospects->total() }} cliente(s) ativo(s). MRR vinculado: <x-money :cents="$mrr" />.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.dashboard') }}" class="btn btn--ghost btn--small">Financeiro</a>
            <a href="{{ route('finance.invoices.index') }}" class="btn btn--ghost btn--small">Cobranças</a>
        </div>
    </div>

    @if ($withoutContract > 0)
        <div class="flash" style="background:#2a1f0a;border-color:#fbbf24;color:#fde68a;">
            <strong>{{ $withoutContract }}</strong> cliente(s) sem contrato ativo. Use <em>Vincular contrato</em> para começar a cobrar.
        </div>
    @endif

    <form method="get" action="{{ route('finance.clients') }}" class="sales-toolbar">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar por nome, Instagram ou telefone">
        <select name="mode">
            <option value="">Todos os modos</option>
            <option value="pix" @selected(request('mode') === 'pix')>PIX</option>
            <option value="card" @selected(request('mode') === 'card')>Cartão</option>
        </select>
        <label class="sales-check">
            <input type="checkbox" name="without_contract" value="1" @checked(request()->boolean('without_contract'))>
            Só sem contrato
        </label>
        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('finance.clients') }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    @if ($prospects->isEmpty())
        <p class="sales-empty">Nenhum cliente ativo com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Contato</th>
                    <th>Plano</th>
                    <th>Modo</th>
                    <th>Status</th>
                    <th>Mensal</th>
                    <th>Próxima</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prospects as $prospect)
                    @php($contract = $prospect->activeContract)
                    <tr>
                        <td>
                            <strong>{{ $prospect->name }}</strong>
                            @if ($prospect->artist)
                                <div class="sales-sub">Artista: {{ $prospect->artist->name }} · {{ $prospect->artist->status }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $prospect->displayPhone() }}
                            @if ($prospect->instagram)
                                <div class="sales-sub">&#64;{{ $prospect->instagram }}</div>
                            @endif
                        </td>
                        <td>{{ $contract?->planLabel() ?? '—' }}</td>
                        <td>{{ $contract?->billingModeLabel() ?? '—' }}</td>
                        <td>
                            @if ($contract)
                                <span class="fin-badge fin-badge--{{ $contract->statusTone() }}">{{ $contract->statusLabel() }}</span>
                            @else
                                <span class="fin-badge fin-badge--pending">Sem contrato</span>
                            @endif
                        </td>
                        <td>
                            @if ($contract)
                                <x-money :cents="$contract->monthly_value_cents" />
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($contract?->nextBillingDate())
                                {{ $contract->nextBillingDate()->format('d/m/Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <div class="fin-actions">
                                @if ($contract)
                                    <a href="{{ route('finance.contracts.edit', $contract) }}" class="btn btn--ghost btn--small">Editar</a>
                                    @if ($contract->isPix())
                                        <a href="{{ route('finance.invoices.create', ['contract' => $contract->id]) }}" class="btn btn--small">Cobrança</a>
                                    @else
                                        <a href="{{ route('finance.payments.index', ['contract' => $contract->id]) }}" class="btn btn--small">Registrar cartão</a>
                                    @endif
                                @else
                                    <a href="{{ route('finance.contracts.create', $prospect) }}" class="btn btn--small">Vincular contrato</a>
                                @endif
                                <a href="{{ route('sales.prospects.show', $prospect) }}" class="btn btn--ghost btn--small">Prospect</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $prospects->links() }}</div>
    @endif
</div>
@endsection
