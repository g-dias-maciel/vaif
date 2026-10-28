@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
@endpush

@section('title', 'Agenda')

@section('content')
<h1 class="page-title">Agenda — {{ $artist?->displayName() }}</h1>

<div class="pricing-tabs">
    <a class="pricing-tab {{ $filter === 'upcoming' ? 'active' : '' }}" href="{{ route('bookings.index', ['filter' => 'upcoming']) }}">Próximos</a>
    <a class="pricing-tab {{ $filter === 'past' ? 'active' : '' }}" href="{{ route('bookings.index', ['filter' => 'past']) }}">Passados</a>
    <a class="pricing-tab {{ $filter === 'all' ? 'active' : '' }}" href="{{ route('bookings.index', ['filter' => 'all']) }}">Todos</a>
</div>

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Bloquear horário</h2>
    <p class="meta">Bloqueie um período para a Beatriz não agendar clientes. Deixe os horários vazios para bloquear o dia inteiro.</p>

    @if ($errors->has('date'))
        <div class="flash error">{{ $errors->first('date') }}</div>
    @endif

    <form method="post" action="{{ route('bookings.block') }}" class="pricing-add">
        @csrf
        <input type="text" name="date" id="block-date" placeholder="dd/mm/aaaa" autocomplete="off" inputmode="numeric" required>
        <input type="time" name="start_time" title="Início (opcional)">
        <input type="time" name="end_time" title="Fim (opcional)">
        <button type="submit" class="btn btn--small">Bloquear</button>
    </form>
</div>

<h2 class="section-title">Agendamentos</h2>
@include('partials.bookings-list', ['bookings' => $bookings, 'readonly' => false])

<h2 class="section-title">Horários bloqueados</h2>
<ul class="slot-list">
    @forelse ($blocked as $b)
        <li>
            <div class="slot-main">
                <span>{{ $b->start_at->format('d/m/Y H:i') }} – {{ $b->end_at->format('d/m/Y H:i') }}</span>
            </div>
            <form method="post" action="{{ route('bookings.unblock', $b) }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--danger btn--small">Desbloquear</button>
            </form>
        </li>
    @empty
        <li class="empty">Nenhum horário bloqueado.</li>
    @endforelse
</ul>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/pt.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.flatpickr) return;

    flatpickr('#block-date', {
        locale: 'pt',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        disableMobile: true
    });
});
</script>
@endpush
