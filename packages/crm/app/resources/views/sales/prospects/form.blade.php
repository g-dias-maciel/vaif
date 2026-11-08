@extends('layouts.app')

@section('title', $prospect->exists ? 'Editar prospect' : 'Novo prospect')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $prospect->exists ? 'Editar prospect' : 'Novo prospect' }}</h1>
            <p class="meta" style="margin-top:6px;">Prospect interno da VAIF — não é um lead de tatuagem.</p>
        </div>
        <a href="{{ $prospect->exists ? route('sales.prospects.show', $prospect) : route('sales.pipeline') }}" class="btn btn--ghost">Cancelar</a>
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

    <form method="post" action="{{ $prospect->exists ? route('sales.prospects.update', $prospect) : route('sales.prospects.store') }}" class="sales-form">
        @csrf
        @if ($prospect->exists)
            @method('PUT')
        @endif

        <label>
            Nome *
            <input type="text" name="name" value="{{ old('name', $prospect->name) }}" required>
        </label>

        <label>
            WhatsApp *
            <input type="text" name="whatsapp" value="{{ old('whatsapp', $prospect->whatsapp_raw ?: $prospect->whatsapp_e164) }}" placeholder="(11) 99999-9999" required>
        </label>

        <label>
            Instagram
            <input type="text" name="instagram" value="{{ old('instagram', $prospect->instagram) }}" placeholder="sem @">
        </label>

        <label>
            E-mail
            <input type="email" name="email" value="{{ old('email', $prospect->email) }}">
        </label>

        <label>
            Estúdio
            <input type="text" name="studio" value="{{ old('studio', $prospect->studio) }}">
        </label>

        <label>
            Estilo
            <input type="text" name="style" value="{{ old('style', $prospect->style) }}">
        </label>

        <label>
            Cidade
            <input type="text" name="city" value="{{ old('city', $prospect->city) }}">
        </label>

        <label>
            Fonte
            <input type="text" name="source" value="{{ old('source', $prospect->source ?: 'manual') }}" placeholder="manual, calculadora, indicação...">
        </label>

        <label>
            Receita mensal (R$)
            <input type="number" step="0.01" min="0" name="revenue_monthly" value="{{ old('revenue_monthly', $prospect->revenue_monthly_cents !== null ? number_format($prospect->revenue_monthly_cents / 100, 2, '.', '') : '') }}">
        </label>

        <label>
            Ticket médio (R$)
            <input type="number" step="0.01" min="0" name="ticket" value="{{ old('ticket', $prospect->ticket_cents !== null ? number_format($prospect->ticket_cents / 100, 2, '.', '') : '') }}">
        </label>

        <label>
            Sessões por mês
            <input type="number" min="0" name="sessions_monthly" value="{{ old('sessions_monthly', $prospect->sessions_monthly) }}">
        </label>

        <label>
            Horas de admin por dia
            <input type="number" step="0.01" min="0" max="24" name="admin_hours_day" value="{{ old('admin_hours_day', $prospect->admin_hours_day) }}">
        </label>

        <label>
            Campanha
            <select name="campaign_id">
                <option value="">Sem campanha</option>
                @foreach ($campaigns as $campaign)
                    <option value="{{ $campaign->id }}" @selected((string) old('campaign_id', $prospect->campaign_id) === (string) $campaign->id)>{{ $campaign->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Dono
            <select name="owner_id">
                @if (auth()->user()->isStaff())
                    <option value="">Não atribuído</option>
                @endif
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected((string) old('owner_id', $prospect->owner_id) === (string) $owner->id)>{{ $owner->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Próxima ação
            <input type="datetime-local" name="next_action_at" value="{{ old('next_action_at', $prospect->next_action_at?->format('Y-m-d\TH:i')) }}">
        </label>

        <label>
            Nota da próxima ação
            <input type="text" name="next_action_note" value="{{ old('next_action_note', $prospect->next_action_note) }}" placeholder="O que precisa acontecer?">
        </label>

        <label class="full">
            Notas
            <textarea name="notes" placeholder="Contexto, objeções, o que foi combinado...">{{ old('notes', $prospect->notes) }}</textarea>
        </label>

        <div class="full" style="display:flex; gap:10px;">
            <button type="submit" class="btn">{{ $prospect->exists ? 'Salvar alterações' : 'Criar prospect' }}</button>
            <a href="{{ $prospect->exists ? route('sales.prospects.show', $prospect) : route('sales.pipeline') }}" class="btn btn--ghost">Cancelar</a>
        </div>
    </form>
</div>
@endsection
