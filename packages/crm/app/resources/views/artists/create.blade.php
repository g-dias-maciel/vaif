@extends('layouts.app')

@section('title', 'Novo artista')

@section('content')
<div class="panel">
    <a href="{{ route('artists.index') }}" class="back">&larr; Voltar para artistas</a>
    <h1 class="page-title">Novo artista</h1>

    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('artists.store') }}" class="form-grid">
        @csrf

        <div class="form-field">
            <label for="nome">Nome *</label>
            <input type="text" name="nome" id="nome" value="{{ old('nome') }}" required>
        </div>

        <div class="form-field">
            <label for="email">E-mail de acesso (login) *</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required>
        </div>

        <div class="form-field">
            <label for="password">Senha *</label>
            <input type="password" name="password" id="password" required>
        </div>

        <div class="form-field">
            <label for="wa_session_slug">Sessão WAHA (slug)</label>
            <input type="text" name="wa_session_slug" id="wa_session_slug" value="{{ old('wa_session_slug') }}" placeholder="ex: bruno-tattoo">
        </div>

        <div class="form-field">
            <label for="whatsapp_number">WhatsApp (com DDI)</label>
            <input type="text" name="whatsapp_number" id="whatsapp_number" value="{{ old('whatsapp_number') }}" placeholder="5511999990001">
        </div>

        <div class="form-field">
            <label for="instagram_handle">Instagram</label>
            <input type="text" name="instagram_handle" id="instagram_handle" value="{{ old('instagram_handle') }}" placeholder="@artista">
        </div>

        <div class="form-field">
            <label for="timezone">Fuso horário</label>
            <input type="text" name="timezone" id="timezone" value="{{ old('timezone', 'America/Sao_Paulo') }}">
        </div>

        <div class="form-field">
            <label for="deposit_value">Sinal (%)</label>
            <input type="number" name="deposit_value" id="deposit_value" value="{{ old('deposit_value', 30) }}" min="0" max="100">
        </div>

        <div style="grid-column: 1 / -1; margin-top: 8px;">
            <button type="submit" class="btn">Criar artista</button>
        </div>
    </form>
</div>
@endsection
