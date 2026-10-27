@extends('layouts.app')

@section('title', 'Análises')

@section('content')
<h1 class="page-title">Análises — {{ $artist?->nome }}</h1>

<form method="get" action="{{ route('analytics.index') }}" class="period-form">
    <label for="period">Período</label>
    <select name="period" id="period" onchange="this.form.submit()">
        <option value="30d" @selected($period === '30d')>Últimos 30 dias</option>
        <option value="this_month" @selected($period === 'this_month')>Este mês</option>
        <option value="90d" @selected($period === '90d')>Últimos 90 dias</option>
        <option value="all" @selected($period === 'all')>Todos os períodos</option>
    </select>
</form>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value">{{ $total }}</div>
        <div class="stat-label">Leads</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ $won }}</div>
        <div class="stat-label">Clientes novos</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ $conversionRate }}%</div>
        <div class="stat-label">Conversão</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ $lostRate }}%</div>
        <div class="stat-label">Taxa de perda</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">R$ {{ number_format($avgTicketCents / 100, 2, ',', '.') }}</div>
        <div class="stat-label">Ticket médio</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">R$ {{ number_format($revenueCents / 100, 2, ',', '.') }}</div>
        <div class="stat-label">Faturamento</div>
    </div>
</div>

<div class="panel" style="margin-top: 24px;">
    <h2 class="section-title" style="margin-top:0;">Conversões mês a mês</h2>
    <div style="position: relative; height: 300px;">
        <canvas id="monthConversionsChart"></canvas>
    </div>
</div>

<div class="panel" style="margin-top: 24px;">
    <h2 class="section-title" style="margin-top:0;">Faturamento mês a mês</h2>
    <div style="position: relative; height: 300px;">
        <canvas id="monthRevenueChart"></canvas>
    </div>
</div>

<div class="panel" style="margin-top: 24px;">
    <h2 class="section-title" style="margin-top:0;">Pipeline</h2>
    <div style="position: relative; height: 300px;">
        <canvas id="pipelineChart"></canvas>
    </div>
</div>

<div class="panel" style="margin-top: 24px;">
    <h2 class="section-title" style="margin-top:0;">Origem dos leads</h2>
    <div style="position: relative; height: 300px;">
        <canvas id="sourceChart"></canvas>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    Chart.defaults.color = '#a09a8e';
    Chart.defaults.font.family = "'Montserrat', sans-serif";

    const brl = (v) => 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const monthLabels = @json($monthLabels);
    const monthWon = @json($monthWon);
    const monthRate = @json($monthRate);
    const monthRevenue = @json($monthRevenue);
    const pipelineLabels = @json($pipelineLabels);
    const pipelineCounts = @json($pipelineCounts);
    const sourceLabels = @json($sourceLabels);
    const sourceCounts = @json($sourceCounts);

    // Conversions month over month: won count (bars) + conversion rate (line)
    new Chart(document.getElementById('monthConversionsChart'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: 'Conversões',
                    data: monthWon,
                    backgroundColor: '#D4B04C',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 2,
                },
                {
                    label: 'Taxa de conversão',
                    data: monthRate,
                    type: 'line',
                    borderColor: '#4ade80',
                    backgroundColor: '#4ade80',
                    tension: 0.3,
                    yAxisID: 'y1',
                    order: 1,
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#222222' }, title: { display: true, text: 'Conversões' } },
                y1: { beginAtZero: true, position: 'right', ticks: { callback: (v) => v + '%' }, grid: { drawOnChartArea: false }, title: { display: true, text: 'Taxa' } },
                x: { grid: { display: false } },
            },
        },
    });

    // Revenue month over month
    new Chart(document.getElementById('monthRevenueChart'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Faturamento',
                data: monthRevenue,
                backgroundColor: '#8f7a35',
                borderRadius: 4,
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => brl(ctx.parsed.y) } },
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#222222' }, ticks: { callback: (v) => brl(v) } },
                x: { grid: { display: false } },
            },
        },
    });

    // Pipeline distribution
    new Chart(document.getElementById('pipelineChart'), {
        type: 'bar',
        data: {
            labels: pipelineLabels,
            datasets: [{
                label: 'Leads',
                data: pipelineCounts,
                backgroundColor: '#D4B04C',
                borderRadius: 4,
                maxBarThickness: 40,
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#222222' } },
                x: { grid: { display: false } },
            },
        },
    });

    // Lead source
    new Chart(document.getElementById('sourceChart'), {
        type: 'doughnut',
        data: {
            labels: sourceLabels,
            datasets: [{
                data: sourceCounts,
                backgroundColor: ['#D4B04C', '#8f7a35', '#5c4f22', '#c9b77a', '#a8923f', '#7a6a3a', '#6e5a2a'],
                borderColor: '#0A0A0A',
                borderWidth: 2,
            }],
        },
        options: {
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } },
        },
    });
})();
</script>
@endpush
