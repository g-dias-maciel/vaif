@extends('layouts.app')

@section('title', 'Equipe')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px;">
        <div>
            <h1 style="margin:0;">Equipe</h1>
            <p class="meta" style="margin-top:6px;">Usuários internos e permissões. Logins de artistas são gerenciados em Artistas.</p>
        </div>
        <a href="{{ route('team.create') }}" class="btn btn--small">Novo usuário</a>
    </div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="sales-grid" style="margin-bottom:18px;">
        <div>
            <div class="label">Administradores</div>
            <div class="value">{{ $staffCount }}</div>
        </div>
        <div>
            <div class="label">Vendas</div>
            <div class="value">{{ $salesCount }}</div>
        </div>
        <div>
            <div class="label">Total interno</div>
            <div class="value">{{ $users->count() }}</div>
        </div>
    </div>

    @if ($users->isEmpty())
        <p class="sales-empty">Nenhum usuário interno cadastrado.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Usuário</th>
                    <th>E-mail</th>
                    <th>Papel</th>
                    <th>Criado em</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    @php
                        $isSelf = $user->id === auth()->id();
                        $isLastStaff = $user->isStaff() && $staffCount <= 1;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $user->name }}</strong>
                            @if ($isSelf)
                                <div class="sales-sub">você</div>
                            @endif
                        </td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="band {{ $user->isStaff() ? 'band--full' : 'band--entry' }}">{{ $user->roleLabel() }}</span>
                        </td>
                        <td>{{ $user->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td>
                            <div class="sales-toolbar" style="margin:0; gap:6px;">
                                <a href="{{ route('team.edit', $user) }}" class="btn btn--ghost btn--small">Editar</a>
                                @if (! $isSelf && ! $isLastStaff)
                                    <form method="post" action="{{ route('team.destroy', $user) }}" onsubmit="return confirm('Remover este usuário? Os prospects dele serão transferidos para você.');">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="btn btn--danger btn--small">Excluir</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="meta" style="margin-top:16px;">
        <strong>Administrador:</strong> acesso total, incluindo financeiro e equipe.
        <strong>Vendas:</strong> pipeline de vendas, backlog e campanhas; sem financeiro nem equipe.
    </p>
</div>
@endsection
