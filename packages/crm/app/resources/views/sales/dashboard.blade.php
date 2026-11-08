@extends('layouts.app')

@section('title', 'Vendas')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="margin:0;">Cockpit de vendas</h1>
            <p class="meta" style="margin-top:6px;">Pipeline interno da VAIF — prospects, não leads de tatuagem.</p>
        </div>
        <a href="{{ route('sales.pipeline') }}" class="btn">Abrir pipeline</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value">{{ $active }}</div>
            <div class="stat-label">Prospects ativos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $backlog }}</div>
            <div class="stat-label">No backlog</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $bookedBacklog }}</div>
            <div class="stat-label">Backlog com call agendada</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">R$ {{ number_format($mrrCents / 100, 0, ',', '.') }}</div>
            <div class="stat-label">MRR contratado</div>
        </div>
    </div>

    <h2 class="section-title">Pipeline por estágio</h2>
    <div class="stat-grid">
        @foreach ($stages as $stage)
            <div class="stat-card">
                <div class="stat-value">{{ $stage->prospects_count }}</div>
                <div class="stat-label">{{ $stage->label_pt }}</div>
            </div>
        @endforeach
    </div>

    <h2 class="section-title">Ativos por banda</h2>
    <div class="stat-grid">
        @foreach (\App\Models\Prospect::bandLabels() as $key => $label)
            <div class="stat-card">
                <div class="stat-value">{{ $bands[$key] ?? 0 }}</div>
                <div class="stat-label">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    <h2 class="section-title">Atualizados recentemente</h2>

    @if ($recent->isEmpty())
        <p class="sales-empty">Nenhum prospect ativo ainda. Importe o backlog ou cadastre o primeiro prospect.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Prospect</th>
                    <th>Banda</th>
                    <th>Receita</th>
                    <th>Estágio</th>
                    <th>Dono</th>
                    <th>Próxima ação</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $prospect)
                    <tr>
                        <td>
                            <a href="{{ route('sales.prospects.show', $prospect) }}"><strong>{{ $prospect->name }}</strong></a>
                            @if ($prospect->instagram)
                                <div class="sales-sub">&#64;{{ $prospect->instagram }}</div>
                            @endif
                        </td>
                        <td>@include('sales.partials.band', ['prospect' => $prospect])</td>
                        <td>{{ $prospect->revenueLabel() }}</td>
                        <td>{{ $prospect->stage?->label_pt ?? '—' }}</td>
                        <td>{{ $prospect->owner?->name ?? '—' }}</td>
                        <td>{{ $prospect->next_action_at?->format('d/m H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($campaigns->isNotEmpty())
        <h2 class="section-title">Campanhas recentes</h2>
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Campanha</th>
                    <th>Canal</th>
                    <th>Status</th>
                    <th>Gasto</th>
                    <th>Prospects</th>
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
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
