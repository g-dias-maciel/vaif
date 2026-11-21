@php
    $active = $active ?? 'overview';
    $navMonth = $navMonth ?? (request()->filled('month') ? request('month') : now());
    $navMonth = $navMonth instanceof \Carbon\CarbonInterface ? $navMonth : \Carbon\Carbon::parse($navMonth);
    $backUrl = $backUrl ?? null;
@endphp

<nav class="fin-subnav" aria-label="Navegação financeira">
    @if ($backUrl)
        <a href="{{ $backUrl }}" class="fin-subnav__back">&larr; Voltar</a>
    @endif
    <a href="{{ route('finance.dashboard') }}" class="fin-subnav__item {{ $active === 'overview' ? 'is-active' : '' }}">Visão anual</a>
    <a href="{{ route('finance.months', ['month' => $navMonth->format('Y-m')]) }}" class="fin-subnav__item {{ $active === 'month' ? 'is-active' : '' }}">Mês {{ $navMonth->format('m/Y') }}</a>
    <a href="{{ route('finance.invoices.index', ['month' => $navMonth->format('Y-m')]) }}" class="fin-subnav__item {{ $active === 'invoices' ? 'is-active' : '' }}">Cobranças</a>
    <a href="{{ route('finance.expenses.index', ['month' => $navMonth->format('Y-m')]) }}" class="fin-subnav__item {{ $active === 'expenses' ? 'is-active' : '' }}">Despesas</a>
    <a href="{{ route('finance.payments.index', ['month' => $navMonth->format('Y-m')]) }}" class="fin-subnav__item {{ $active === 'payments' ? 'is-active' : '' }}">Recebimentos</a>
    <a href="{{ route('finance.clients') }}" class="fin-subnav__item {{ $active === 'contracts' ? 'is-active' : '' }}">Contratos</a>
    <a href="{{ route('finance.settings') }}" class="fin-subnav__item {{ $active === 'settings' ? 'is-active' : '' }}">Configuração</a>
</nav>
