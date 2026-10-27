@extends('layouts.app')

@section('title', $artist->nome)

@section('content')
<a href="{{ route('artists.index') }}" class="back">&larr; Voltar para artistas</a>
<h1 class="page-title">{{ $artist->nome ?: $artist->display_name }}</h1>

<h2 class="section-title">Agendamentos</h2>
@include('partials.bookings-list', ['bookings' => $bookings, 'readonly' => false])

<h2 class="section-title">Pipeline</h2>
@include('partials.lead-board', ['leads' => $leads, 'pipeline' => $pipeline, 'readonly' => false])
@endsection
