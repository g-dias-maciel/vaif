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

    @if ($editing)
        <hr style="border-color: var(--border); margin: 28px 0 20px;">

        <h2 class="section-title" style="margin-top:0;">Google Agenda</h2>

        @if (! $googleConfigured)
            <div class="flash" style="background:#2a1f0a;border-color:#fbbf24;color:#fde68a;">
                A integração Google não está configurada no servidor. Defina <code>GOOGLE_SERVICE_ACCOUNT_JSON</code> para conectar agendas.
            </div>
        @elseif ($user->hasGoogleCalendar())
            <p class="meta" style="margin-bottom:12px;">
                Eventos atribuídos a este usuário são espelhados na agenda abaixo.
                O organizador no Google será a conta de serviço VAIF.
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:16px;">
                <div class="fin-card" style="padding:12px;border:1px solid var(--border);border-radius:10px;">
                    <div class="meta" style="font-size:.62rem;text-transform:uppercase;letter-spacing:1px;">Agenda conectada</div>
                    <strong>{{ $user->google_calendar_summary ?: $user->google_calendar_id }}</strong>
                    <div class="meta">{{ $user->google_calendar_id }}</div>
                    <div class="meta">Desde {{ $user->google_connected_at?->format('d/m/Y H:i') ?? '—' }}</div>
                </div>
                <div class="fin-card" style="padding:12px;border:1px solid var(--border);border-radius:10px;">
                    <div class="meta" style="font-size:.62rem;text-transform:uppercase;letter-spacing:1px;">Fila de sincronização</div>
                    <strong>{{ $googlePending }} evento(s) pendente(s)</strong>
                    @if ($googleSyncErrors > 0)
                        <div style="color:#f87171;">{{ $googleSyncErrors }} evento(s) com erro</div>
                    @endif
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <form method="post" action="{{ route('team.google.sync', $user) }}">
                    @csrf
                    <button type="submit" class="btn btn--small">Sincronizar agora</button>
                </form>
                <form method="post" action="{{ route('team.google.disconnect', $user) }}" onsubmit="return confirm('Desconectar a Google Agenda deste usuário? Os eventos serão removidos da agenda antiga.');">
                    @csrf
                    @method('delete')
                    <button type="submit" class="btn btn--danger btn--small">Desconectar</button>
                </form>
            </div>
        @else
            <p class="meta" style="margin-bottom:12px;">
                Compartilhe a agenda do usuário com a conta de serviço
                <strong>{{ $googleServiceEmail ?: 'VAIF' }}</strong> e dê permissão
                <strong>“Fazer alterações nos eventos”</strong>. O evento aparecerá na agenda do responsável; o organizador no Google será a conta de serviço.
            </p>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                <form method="post" action="{{ route('team.google.detect', $user) }}">
                    @csrf
                    <button type="submit" class="btn btn--ghost btn--small">Detectar agendas compartilhadas</button>
                </form>
            </div>

            @if (! empty($googleCalendars))
                <form method="post" action="{{ route('team.google.connect', $user) }}" class="sales-form" style="margin-bottom:14px;">
                    @csrf
                    <label class="full">
                        Agendas encontradas
                        <select name="calendar_id" required>
                            @foreach ($googleCalendars as $calendar)
                                <option value="{{ $calendar['id'] }}">
                                    {{ $calendar['summary'] ?? $calendar['id'] }} — {{ $calendar['id'] }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <div class="full">
                        <button type="submit" class="btn">Conectar agenda selecionada</button>
                    </div>
                </form>
            @endif

            <form method="post" action="{{ route('team.google.connect', $user) }}" class="sales-form">
                @csrf
                <label class="full">
                    ID da agenda
                    <input type="text" name="calendar_id" value="{{ old('calendar_id') }}" placeholder="ex.: nome@gmail.com ou xxxx@group.calendar.google.com" required>
                </label>
                <div class="full">
                    <button type="submit" class="btn">Conectar pelo ID</button>
                </div>
            </form>
        @endif
    @endif
</div>
@endsection
