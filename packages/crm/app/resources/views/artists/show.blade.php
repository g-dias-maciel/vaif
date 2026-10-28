@extends('layouts.app')

@section('title', $artist->displayName())

@section('content')
<a href="{{ route('artists.index') }}" class="back">&larr; Voltar para artistas</a>
<h1 class="page-title">{{ $artist->displayName() }}</h1>

<h2 class="section-title">Agendamentos</h2>
@include('partials.bookings-list', ['bookings' => $bookings, 'readonly' => false])

<h2 class="section-title">Pipeline</h2>
@include('partials.project-board', ['projects' => $projects, 'pipeline' => $pipeline, 'readonly' => false])
@endsection
