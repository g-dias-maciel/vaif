@extends('layouts.app')

@section('title', 'Artistas')

@section('content')
<div class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <h1 style="margin:0;">Artistas</h1>
        <a href="{{ route('artists.create') }}" class="btn">Novo artista</a>
    </div>
    <ul class="artist-list">
        @forelse ($artists as $a)
            <li>
                <a href="{{ route('artists.select', $a) }}">
                    <strong>{{ $a->displayName() }}</strong>
                    <span class="meta">{{ $a->leads_count }} lead(s)</span>
                    <span class="badge">{{ $a->status }}</span>
                </a>
                <form method="post" action="{{ route('artists.toggle', $a) }}" class="inline">
                    @csrf
                    @if ($a->status === 'live' || $a->status === 'onboarding')
                        <button type="submit" class="btn btn--danger btn--small">Pausar agente</button>
                    @elseif ($a->status === 'suspended')
                        <button type="submit" class="btn btn--small">Reativar agente</button>
                    @endif
                </form>
            </li>
        @empty
            <li class="empty">Nenhum artista cadastrado.</li>
        @endforelse
    </ul>
</div>
@endsection
