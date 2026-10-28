@extends('layouts.app')

@section('title', 'Definir senha')

@section('content')
<div class="login-card">
    <img src="{{ asset('img/vaif_logo.webp') }}" alt="VAIF" class="login-logo">
    <h1>Definir senha</h1>

    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('password.set.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label>
            E-mail
            <input type="email" name="email" value="{{ old('email', $email) }}" required>
        </label>
        <label>
            Nova senha
            <input type="password" name="password" required autofocus>
        </label>
        <label>
            Confirme a nova senha
            <input type="password" name="password_confirmation" required>
        </label>

        <button type="submit" class="btn" style="width:100%;">Salvar senha</button>
    </form>
</div>
@endsection
