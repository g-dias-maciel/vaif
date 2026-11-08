@extends('layouts.app')

@section('title', 'Backlog de vendas')

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:12px;">
        <div>
            <h1 style="margin:0;">Backlog</h1>
            <p class="meta" style="margin-top:6px;">{{ $prospects->count() }} prospect(s) importados da calculadora. Priorize por banda e por call agendada.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('sales.pipeline') }}" class="btn btn--ghost">Abrir pipeline</a>
            <a href="{{ route('sales.dashboard') }}" class="btn btn--ghost">Cockpit</a>
        </div>
    </div>

    <div class="flash" style="background:#0a1f2a;border-color:#60a5fa;color:#93c5fd;">
        Quem tem <strong>call agendada</strong> já conversou com a VAIF no passado. Esses são os primeiros da fila.
    </div>

    <form method="get" action="{{ route('sales.backlog') }}" class="sales-toolbar">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar por nome ou Instagram">

        <select name="band">
            <option value="">Todas as bandas</option>
            @foreach ($bands as $key => $label)
                <option value="{{ $key }}" @selected(request('band') === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="source">
            <option value="">Todas as fontes</option>
            @foreach ($sources as $source)
                <option value="{{ $source }}" @selected(request('source') === $source)>{{ $source }}</option>
            @endforeach
        </select>

        <label class="sales-check">
            <input type="checkbox" name="booked" value="1" @checked(request()->boolean('booked'))>
            Só com call agendada
        </label>

        <label class="sales-check">
            <input type="checkbox" name="with_revenue" value="1" @checked(request()->boolean('with_revenue'))>
            Só com receita
        </label>

        <button type="submit" class="btn btn--small">Filtrar</button>
        <a href="{{ route('sales.backlog') }}" class="btn btn--ghost btn--small">Limpar</a>
    </form>

    @if ($prospects->isEmpty())
        <p class="sales-empty">Nenhum prospect no backlog com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Prospect</th>
                    <th>Banda</th>
                    <th>Receita</th>
                    <th>Fonte</th>
                    <th>Call agendada</th>
                    <th>Dono</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prospects as $prospect)
                    <tr>
                        <td>
                            <strong>{{ $prospect->name }}</strong>
                            <div class="sales-sub">
                                {{ $prospect->displayPhone() }}
                                @if ($prospect->instagram)
                                    · &#64;{{ $prospect->instagram }}
                                @endif
                            </div>
                        </td>
                        <td>@include('sales.partials.band', ['prospect' => $prospect])</td>
                        <td>{{ $prospect->revenueLabel() }}</td>
                        <td>{{ $prospect->source }}</td>
                        <td>{{ $prospect->booked_call_at?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $prospect->owner?->name ?? '—' }}</td>
                        <td>
                            <form method="post" action="{{ route('sales.backlog.promote', $prospect) }}">
                                @csrf
                                <button type="submit" class="btn btn--small">Promover</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
