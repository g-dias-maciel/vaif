@extends('layouts.app')

@section('title', 'Pipeline de vendas')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="margin:0;">Pipeline</h1>
            <p class="meta" style="margin-top:6px;">{{ $prospects->count() }} prospect(s) neste filtro.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('sales.prospects.create') }}" class="btn">Novo prospect</a>
            <a href="{{ route('sales.backlog') }}" class="btn btn--ghost">Ver backlog</a>
            <a href="{{ route('sales.dashboard') }}" class="btn btn--ghost">Cockpit</a>
        </div>
    </div>

    <form method="get" action="{{ route('sales.pipeline') }}" class="sales-toolbar">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar por nome, Instagram ou WhatsApp">

        <select name="status">
            <option value="active" @selected($status === 'active')>Ativos</option>
            <option value="backlog" @selected($status === 'backlog')>Backlog</option>
            <option value="archived" @selected($status === 'archived')>Arquivados</option>
        </select>

        <select name="stage">
            <option value="">Todos os estágios</option>
            @foreach ($stages as $stage)
                <option value="{{ $stage->key }}" @selected(request('stage') === $stage->key)>{{ $stage->label_pt }}</option>
            @endforeach
        </select>

        <select name="band">
            <option value="">Todas as bandas</option>
            @foreach ($bands as $key => $label)
                <option value="{{ $key }}" @selected(request('band') === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="owner">
            <option value="">Todos os donos</option>
            @foreach ($owners as $owner)
                <option value="{{ $owner->id }}" @selected((string) request('owner') === (string) $owner->id)>{{ $owner->name }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('sales.pipeline') }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    @if ($prospects->isEmpty())
        <p class="sales-empty">Nenhum prospect encontrado com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Prospect</th>
                    <th>Banda</th>
                    <th>Receita</th>
                    <th>Estágio</th>
                    <th>Dono</th>
                    <th>Próxima ação</th>
                    <th>Atualizado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prospects as $prospect)
                    <tr>
                        <td>
                            <a href="{{ route('sales.prospects.show', $prospect) }}"><strong>{{ $prospect->name }}</strong></a>
                            <div class="sales-sub">
                                {{ $prospect->displayPhone() }}
                                @if ($prospect->instagram)
                                    · &#64;{{ $prospect->instagram }}
                                @endif
                            </div>
                        </td>
                        <td>@include('sales.partials.band', ['prospect' => $prospect])</td>
                        <td>{{ $prospect->revenueLabel() }}</td>
                        <td>{{ $prospect->stage?->label_pt ?? '—' }}</td>
                        <td>{{ $prospect->owner?->name ?? '—' }}</td>
                        <td>{{ $prospect->next_action_at?->format('d/m H:i') ?? '—' }}</td>
                        <td>{{ $prospect->updated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
