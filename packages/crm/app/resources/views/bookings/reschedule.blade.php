@extends('layouts.app')

@section('title', 'Remarcar')

@section('content')
<div class="panel">
    <h1>Remarcar agendamento</h1>
    <p class="meta">
        {{ $slot->lead?->nome ?: 'Cliente' }} — atualmente
        <strong>{{ $slot->start_at->format('d/m/Y H:i') }}</strong>
    </p>

    @if ($errors->any())
        <div class="flash error">{{ $errors->first('start_at') }}</div>
    @endif

    @if (empty($available))
        <p class="empty" style="margin-top:16px;">Nenhum horário livre nos próximos dias.</p>
    @else
        <form method="post" action="{{ route('bookings.reschedule.store', $slot) }}">
            @csrf
            <div class="slots">
                @foreach ($available as $s)
                    <label class="slot-option">
                        <input type="radio" name="start_at" value="{{ $s['start_at']->toIso8601String() }}" required>
                        {{ $s['start_at']->format('d/m/Y H:i') }} – {{ $s['end_at']->format('H:i') }}
                    </label>
                @endforeach
            </div>
            <button type="submit" class="btn">Confirmar novo horário</button>
        </form>
    @endif
</div>
@endsection
