@extends('layouts.app')

@section('title', $artist->displayName())

@section('content')
<a href="{{ route('artists.index') }}" class="back">&larr; Voltar para artistas</a>
<h1 class="page-title">{{ $artist->displayName() }}</h1>

<form method="post" action="{{ route('artists.invite', $artist) }}" style="margin: -8px 0 24px;">
    @csrf
    <button type="submit" class="btn" style="background: transparent; border: 1px solid var(--border); color: var(--muted);">
        Reenviar convite de senha
    </button>
</form>

<h2 class="section-title">Agendamentos</h2>
@include('partials.bookings-list', ['bookings' => $bookings, 'readonly' => false])

<h2 class="section-title">Pipeline</h2>
@include('partials.project-board', ['projects' => $projects, 'pipeline' => $pipeline, 'readonly' => false])
@endsection
