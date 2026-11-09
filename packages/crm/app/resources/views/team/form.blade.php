@extends('layouts.app')

@section('title', $user->exists ? 'Editar usuário' : 'Novo usuário')

@section('content')
@include('sales.partials.styles')

@php($editing = $user->exists)

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $editing ? 'Editar usuário' : 'Novo usuário' }}</h1>
            <p class="meta" style="margin-top:6px;">
                {{ $editing ? 'Atualize os dados, o papel ou a senha do usuário.' : 'Crie um login interno e defina o papel de acesso.' }}
            </p>
        </div>
        <a href="{{ route('team.index') }}" class="btn btn--ghost btn--small">Voltar</a>
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

    <form method="post" action="{{ $editing ? route('team.update', $user) : route('team.store') }}" class="sales-form">
        @csrf
        @if ($editing)
            @method('put')
        @endif

        <label>
            Nome
            <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
        </label>

        <label>
            E-mail
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
        </label>

        <label>
            Papel
            <select name="role" required>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            {{ $editing ? 'Nova senha (opcional)' : 'Senha' }}
            <input type="password" name="password" autocomplete="new-password" {{ $editing ? '' : 'required' }}>
        </label>

        <label>
            Confirmar senha
            <input type="password" name="password_confirmation" autocomplete="new-password" {{ $editing ? '' : 'required' }}>
        </label>

        <div class="full">
            <p class="meta" style="margin-bottom:10px;">
                <strong>Administrador:</strong> acesso total, incluindo financeiro e equipe.
                <strong>Vendas:</strong> pipeline, backlog e campanhas, sem financeiro nem equipe.
                @if ($editing)
                    Deixe a senha em branco para manter a atual.
                @endif
            </p>
            <button type="submit" class="btn">{{ $editing ? 'Salvar usuário' : 'Criar usuário' }}</button>
        </div>
    </form>
</div>
@endsection
