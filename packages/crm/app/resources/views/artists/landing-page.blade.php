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
            @if (config('content.admin.token'))
                <a href="{{ rtrim((string) config('content.admin.url'), '/') . '/' . rawurlencode((string) config('content.admin.token')) }}"
                   target="_blank" rel="noopener noreferrer" class="btn btn--ghost">Painel de artistas da LP</a>
            @endif
            <a href="{{ route('artists.edit', $artist) }}" class="btn btn--ghost">Editar slug</a>
        </div>
    </div>
</div>

@if ($error)
    <div class="flash error" style="margin-bottom:18px;">{{ $error }}</div>
@endif

<div class="panel" style="margin-bottom:18px;">
    <h2 class="section-title" style="margin-top:0;">Formulário para o artista</h2>
    <p class="meta" style="margin-top:0; max-width:820px;">
        Gere um link de uso único para o artista preencher o primeiro cadastro da landing page:
        nome, contato, bio, fotos, portfólio, antes/depois, depoimentos, FAQ, endereço e horários.
        O link já leva nome, WhatsApp e Instagram preenchidos a partir do CRM.
    </p>
    <p class="meta" style="margin-top:0;">
        Use para o primeiro cadastro. Para editar uma página já publicada, use o painel de artistas da LP.
    </p>

    @if (session('intake_link'))
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin:14px 0;">
            <input id="intake-link" type="text" readonly value="{{ session('intake_link') }}"
                style="flex:1; min-width:260px; background:var(--bg); color:var(--ink); border:1px solid var(--border); border-radius:8px; padding:10px; font-family:inherit; font-size:.82rem;">
            <button type="button" class="btn"
                onclick="navigator.clipboard.writeText(document.getElementById('intake-link').value).then(()=>{this.textContent='Copiado'; setTimeout(()=>this.textContent='Copiar link',1500);})">
                Copiar link
            </button>
        </div>
        <p class="meta">Link de uso único. Depois do envio, a ficha fica como rascunho no painel de artistas da LP para revisão.</p>
    @endif

    <form method="post" action="{{ route('artists.intake-link', $artist) }}" style="margin-top:12px;">
        @csrf
        <button class="btn" type="submit">Gerar link para o artista</button>
    </form>
</div>

@if ($profile)
    @include('partials.artist-facts', ['facts' => $profile])
@elseif (! $error)
    <div class="panel">
        <p class="meta" style="margin:0;">Nenhum dado retornado pela landing page.</p>
    </div>
@endif
@endsection
