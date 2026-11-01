@extends('layouts.app')

@section('title', 'Editar '.$artist->displayName())

@section('content')
<div class="panel">
    <a href="{{ route('artists.show', $artist) }}" class="back">&larr; Voltar para o artista</a>
    <h1 class="page-title">Editar {{ $artist->displayName() }}</h1>

    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('artists.update', $artist) }}" class="form-grid">
        @csrf
        @method('PUT')

        <div class="form-field">
            <label for="name">Nome *</label>
            <input type="text" name="name" id="name" value="{{ old('name', $artist->name) }}" required>
        </div>

        <div class="form-field">
            <label for="email">E-mail de acesso (login) *</label>
            <input type="email" name="email" id="email"
                value="{{ old('email', $user?->email) }}" required>
            @if ($user)
                <span class="meta">Alterar o e-mail muda o login do artista. A senha atual continua a mesma.</span>
            @else
                <span class="meta">Este artista ainda não tem login. Salvar criará a conta e enviará o convite de senha.</span>
            @endif
        </div>

        <div class="form-field">
            <label for="whatsapp_number">WhatsApp (com DDI)</label>
            <input type="text" name="whatsapp_number" id="whatsapp_number"
                value="{{ old('whatsapp_number', $artist->settings?->whatsapp_number) }}"
                placeholder="5511999990001">
        </div>

        <div class="form-field">
            <label for="instagram_handle">Instagram</label>
            <input type="text" name="instagram_handle" id="instagram_handle"
                value="{{ old('instagram_handle', $artist->settings?->instagram_handle) }}"
                placeholder="@artista">
        </div>

        <div class="form-field">
            <label for="timezone">Fuso horário</label>
            <input type="text" name="timezone" id="timezone"
                value="{{ old('timezone', $artist->timezone ?: 'America/Sao_Paulo') }}">
        </div>

        <div class="form-field">
            <label for="status">Status do agente</label>
            <select name="status" id="status" required>
                @foreach (['live' => 'Ativo', 'onboarding' => 'Onboarding', 'suspended' => 'Pausado'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $artist->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-field">
            <label for="deposit_value">Sinal (%)</label>
            <input type="number" name="deposit_value" id="deposit_value"
                value="{{ old('deposit_value', $artist->settings?->deposit_value) }}"
                min="1" max="100" step="0.5">
            <span class="meta">Percentual do valor à vista usado pelo agente.</span>
        </div>

        <div class="form-field">
            <label>Sessão WAHA</label>
            <input type="text" value="{{ $artist->wa_session_slug }}" disabled>
            <span class="meta">O slug da sessão não muda por aqui para não desconectar o WhatsApp.</span>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 8px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="submit" class="btn">Salvar alterações</button>
            <a href="{{ route('artists.show', $artist) }}" class="btn btn--ghost">Cancelar</a>
        </div>
    </form>

    <hr style="border-color: var(--border); margin: 28px 0 20px;">

    <h2 class="section-title" style="color: var(--danger);">Excluir artista</h2>
    <p class="meta">
        Remove o artista, o login, todos os leads, projetos, agendamentos e conversas.
        Esta ação não pode ser desfeita.
    </p>
    <form method="post" action="{{ route('artists.destroy', $artist) }}" style="margin-top: 12px;"
        onsubmit="return confirm('Excluir este artista e TODOS os leads, projetos, agendamentos e conversas? Esta ação não pode ser desfeita.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn--danger">Excluir artista</button>
    </form>
</div>
@endsection
