@extends('layouts.app')

@section('title', $event->exists ? 'Editar compromisso' : 'Novo compromisso')

@section('content')
@include('calendar.partials.styles')

@php($editing = $event->exists)

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $editing ? 'Editar compromisso' : 'Novo compromisso' }}</h1>
            <p class="meta" style="margin-top:6px;">Reuniões internas de vendas, estratégia e outros assuntos.</p>
        </div>
        <a href="{{ route('calendar.index', $editing ? ['month' => $event->starts_at->format('Y-m')] : []) }}" class="btn btn--ghost btn--small">Voltar</a>
    </div>

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ $editing ? route('calendar.events.update', $event) : route('calendar.events.store') }}" class="sales-form">
        @csrf
        @if ($editing)
            @method('put')
        @endif

        <label class="full">
            Título
            <input type="text" name="title" value="{{ old('title', $event->title) }}" required>
        </label>

        <label>
            Tipo
            <select name="type" required>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $event->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Responsável
            <select name="owner_id" required>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected((string) old('owner_id', $event->owner_id) === (string) $owner->id)>
                        {{ $owner->name }} — {{ $owner->roleLabel() }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            Início
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $event->starts_at?->format('Y-m-d\TH:i')) }}" required>
        </label>

        <label>
            Fim
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $event->ends_at?->format('Y-m-d\TH:i')) }}" required>
        </label>

        <label>
            Prospect (opcional)
            <select name="prospect_id">
                <option value="">Sem vínculo</option>
                @foreach ($prospects as $prospect)
                    <option value="{{ $prospect->id }}" @selected((string) old('prospect_id', $event->prospect_id) === (string) $prospect->id)>
                        {{ $prospect->name }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            Local
            <input type="text" name="location" value="{{ old('location', $event->location) }}" placeholder="Escritório, Google Meet, etc.">
        </label>

        <label class="full">
            Link da reunião
            <input type="url" name="meeting_url" value="{{ old('meeting_url', $event->meeting_url) }}" placeholder="https://meet.google.com/...">
        </label>

        <label class="full">
            Observações
            <textarea name="notes">{{ old('notes', $event->notes) }}</textarea>
        </label>

        <div class="full">
            <p class="meta" style="margin-bottom:10px;">
                Calls de prospects são exibidas automaticamente no calendário e não precisam ser criadas aqui.
                Este formulário é para reuniões internas e compromissos manuais.
            </p>
            <div class="cal-actions">
                <button type="submit" class="btn">{{ $editing ? 'Salvar compromisso' : 'Criar compromisso' }}</button>
                <a href="{{ route('calendar.index', $editing ? ['month' => $event->starts_at->format('Y-m')] : []) }}" class="btn btn--ghost">Cancelar</a>
            </div>
        </div>
    </form>

    @if ($editing)
        <hr style="border-color: var(--border); margin: 24px 0 16px;">
        <form method="post" action="{{ route('calendar.events.destroy', $event) }}" onsubmit="return confirm('Remover este compromisso?');">
            @csrf
            @method('delete')
            <button type="submit" class="btn btn--danger">Excluir compromisso</button>
        </form>
    @endif
</div>
@endsection
