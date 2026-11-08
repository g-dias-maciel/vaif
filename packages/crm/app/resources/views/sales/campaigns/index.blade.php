@extends('layouts.app')

@section('title', 'Campanhas de vendas')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="margin:0;">Campanhas</h1>
            <p class="meta" style="margin-top:6px;">Atribuição dos prospects e custo por lead. O gasto é informado manualmente nesta fase.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('sales.pipeline') }}" class="btn btn--ghost">Pipeline</a>
            <a href="{{ route('sales.dashboard') }}" class="btn btn--ghost">Cockpit</a>
        </div>
    </div>

    @if ($campaigns->isEmpty())
        <p class="sales-empty">Nenhuma campanha cadastrada ainda. A criação de campanhas entra na próxima fase.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Campanha</th>
                    <th>Canal</th>
                    <th>Status</th>
                    <th>Gasto</th>
                    <th>Prospects</th>
                    <th>CPL</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($campaigns as $campaign)
                    <tr>
                        <td><strong>{{ $campaign->name }}</strong></td>
                        <td>{{ $campaign->channel }}</td>
                        <td>{{ $campaign->status }}</td>
                        <td>R$ {{ number_format($campaign->spend_cents / 100, 0, ',', '.') }}</td>
                        <td>{{ $campaign->prospects_count }}</td>
                        <td>
                            @if ($campaign->prospects_count > 0)
                                R$ {{ number_format($campaign->spend_cents / $campaign->prospects_count / 100, 0, ',', '.') }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
