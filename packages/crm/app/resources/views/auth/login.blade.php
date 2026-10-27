@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
<div class="login-card">
    <h1>Entrar</h1>

    @if ($errors->any())
        <div class="flash error">{{ $errors->first('email') }}</div>
    @endif

    <form method="post" action="{{ route('login') }}">
        @csrf
        <label>
            E-mail
            <input type="email" name="email" value="{{ old('email') }}" required autofocus>
        </label>
        <label>
            Senha
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn" style="width:100%;">Entrar</button>
    </form>
</div>
@endsection
