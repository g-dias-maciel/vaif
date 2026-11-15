@extends('layouts.app')

@section('title', 'Landing page — ' . $artist->displayName())

@section('content')
<a href="{{ route('artists.show', $artist) }}" class="back">&larr; Voltar para {{ $artist->displayName() }}</a>

<div class="panel" style="margin-bottom:18px;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
        <div>
            <h1 class="page-title" style="margin-bottom:6px;">Landing page</h1>
            <p class="meta" style="margin:0; max-width:780px;">
                Esta é a ficha pública que alimenta a página do artista e a geração semanal do blog.
                Os dados vêm da LP; o CRM apenas lê e mostra.
            </p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('artists.landing-page', $artist) }}" class="btn btn--ghost">Atualizar</a>
            @if ($publicUrl !== '')
                <a href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer" class="btn">Abrir página pública</a>
            @endif
            <a href="{{ route('artists.edit', $artist) }}" class="btn btn--ghost">Editar slug</a>
        </div>
    </div>
</div>

@if ($error)
    <div class="flash error" style="margin-bottom:18px;">{{ $error }}</div>
@endif

@if ($profile)
    @include('partials.artist-facts', ['facts' => $profile])
@elseif (! $error)
    <div class="panel">
        <p class="meta" style="margin:0;">Nenhum dado retornado pela landing page.</p>
    </div>
@endif
@endsection
