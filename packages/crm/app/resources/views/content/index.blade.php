@extends('layouts.app')

@section('title', 'Conteúdo')

@section('content')
<style>
    .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
    .content-stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
    .content-stat strong { display: block; font-size: 1.6rem; color: var(--gold); line-height: 1.1; }
    .content-stat span { display: block; color: var(--muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .6px; margin-top: 5px; }
    .content-table { width: 100%; border-collapse: collapse; }
    .content-table th, .content-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .content-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .content-chip { display: inline-block; font-size: .66rem; letter-spacing: .4px; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
    .content-chip--ready { color: var(--gold); border-color: var(--gold); }
    .content-chip--failed { color: var(--danger, #d05a5a); border-color: var(--danger, #d05a5a); }
    .content-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: end; }
    .content-form label { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 5px; }
    .content-form input, .content-form select, .content-form textarea {
        width: 100%; background: var(--bg); color: var(--ink); border: 1px solid var(--border);
        border-radius: 8px; padding: 9px 10px; font-family: inherit; font-size: .85rem;
    }
    .content-form textarea { min-height: 70px; resize: vertical; }
    .content-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .content-empty { color: var(--muted); font-size: .85rem; padding: 8px 0; }
</style>

<div class="panel" style="margin-bottom:18px;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="margin:0 0 6px;">Conteúdo</h1>
            <p class="meta" style="max-width:840px; margin:0;">
                Calendário editorial, rascunho por IA, revisão do Copiloto e aprovação humana.
                Só o admin publica; o artigo vai para <code>vaif.com.br/blog/</code>.
            </p>
        </div>
        @if ($nextTopic)
            <form method="post" action="{{ route('content.generate') }}">
                @csrf
                <input type="hidden" name="topic_id" value="{{ $nextTopic->id }}">
                <button class="btn" type="submit">Gerar próximo agora</button>
            </form>
        @endif
    </div>

    @if (! $schemaReady)
        <div class="flash error" style="margin-top:14px;">
            A migração de conteúdo ainda não foi aplicada. Rode
            <code>packages/crm/db/migrations/0046_blog_content.sql</code>.
        </div>
    @endif
</div>

@if ($schemaReady)
    <div class="content-grid" style="margin-bottom:18px;">
        <div class="content-stat">
            <strong>{{ $reviews->count() }}</strong>
            <span>Aguardando revisão</span>
        </div>
        <div class="content-stat">
            <strong>{{ $inFlight->count() }}</strong>
            <span>Em andamento / falhou</span>
        </div>
        <div class="content-stat">
            <strong>{{ $upcoming->count() }}</strong>
            <span>Tópicos planejados</span>
        </div>
        <div class="content-stat">
            <strong>{{ $published->count() }}</strong>
            <span>Publicados recentes</span>
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Fila de revisão</h2>
        @if ($reviews->isEmpty())
            <p class="content-empty">Nenhum artigo aguardando revisão.</p>
        @else
            <table class="content-table">
                <thead>
                    <tr><th>Título</th><th>Artista</th><th>Palavra-chave</th><th>Score</th><th>Atualizado</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($reviews as $post)
                        <tr>
                            <td><a href="{{ route('content.show', $post) }}">{{ $post->title ?: 'Sem título' }}</a></td>
                            <td>{{ $post->artist_slug ?: '—' }}</td>
                            <td>{{ $post->target_keyword ?: '—' }}</td>
                            <td>{{ $post->ai_score !== null ? $post->ai_score . '/100' : '—' }}</td>
                            <td>{{ $post->updated_at?->format('d/m H:i') }}</td>
                            <td style="text-align:right;"><a class="btn btn--ghost btn--small" href="{{ route('content.show', $post) }}">Revisar</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($inFlight->isNotEmpty())
        <div class="panel" style="margin-bottom:18px;">
            <h2 class="section-title" style="margin-top:0;">Em andamento</h2>
            <table class="content-table">
                <thead>
                    <tr><th>Título</th><th>Status</th><th>Feedback</th><th>Atualizado</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($inFlight as $post)
                        <tr>
                            <td>{{ $post->title ?: ($post->topic?->target_keyword ?? 'Gerando…') }}</td>
                            <td><span class="content-chip content-chip--{{ $post->statusTone() }}">{{ $post->statusLabel() }}</span></td>
                            <td class="meta">{{ $post->feedback ? \Illuminate\Support\Str::limit($post->feedback, 120) : '—' }}</td>
                            <td>{{ $post->updated_at?->format('d/m H:i') }}</td>
                            <td style="text-align:right;"><a class="btn btn--ghost btn--small" href="{{ route('content.show', $post) }}">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Calendário editorial</h2>
        @if ($upcoming->isEmpty())
            <p class="content-empty">Nenhum tópico planejado. Adicione o primeiro abaixo.</p>
        @else
            <table class="content-table">
                <thead>
                    <tr><th>Data</th><th>Track</th><th>Artista</th><th>Palavra-chave</th><th>Intenção</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($upcoming as $topic)
                        <tr>
                            <td>{{ $topic->publish_date?->format('d/m/Y') }}</td>
                            <td>{{ $topic->label() }}</td>
                            <td>{{ $topic->artist?->displayName() ?? $topic->artist_slug }}</td>
                            <td>{{ $topic->target_keyword }}</td>
                            <td>{{ $topic->search_intent ?: '—' }}</td>
                            <td style="text-align:right;">
                                <form method="post" action="{{ route('content.generate') }}">
                                    @csrf
                                    <input type="hidden" name="topic_id" value="{{ $topic->id }}">
                                    <button class="btn btn--ghost btn--small" type="submit">Gerar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h3 style="margin:22px 0 10px;">Adicionar tópico</h3>
        @if ($artists->isEmpty())
            <p class="content-empty">
                Nenhum artista tem <code>landing_page_slug</code>. Configure em <strong>Artistas → Editar</strong>.
            </p>
        @else
            <form method="post" action="{{ route('content.topics.store') }}" class="content-form">
                @csrf
                <div>
                    <label for="artist_id">Artista</label>
                    <select id="artist_id" name="artist_id" required>
                        @foreach ($artists as $artist)
                            <option value="{{ $artist->id }}">{{ $artist->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="publish_date">Data</label>
                    <input id="publish_date" type="date" name="publish_date" value="{{ now()->next('Monday')->toDateString() }}" required>
                </div>
                <div>
                    <label for="track">Track</label>
                    <select id="track" name="track" required>
                        <option value="local_guide">Guia local</option>
                        <option value="spotlight">Perfil do artista</option>
                        <option value="specialty_guide">Guia de especialidade</option>
                        <option value="case_study">Antes e depois</option>
                        <option value="educational">Educacional</option>
                    </select>
                </div>
                <div>
                    <label for="target_keyword">Palavra-chave</label>
                    <input id="target_keyword" name="target_keyword" placeholder="cobertura de tatuagem Poços de Caldas" required>
                </div>
                <div>
                    <label for="search_intent">Intenção</label>
                    <input id="search_intent" name="search_intent" placeholder="local, comercial, informacional">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="angle">Ângulo / observação</label>
                    <textarea id="angle" name="angle" placeholder="Ex.: falar do antes e depois de uma cobertura de leão; destacar o estúdio no Jardim Santa Rita."></textarea>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button class="btn" type="submit">Adicionar ao calendário</button>
                </div>
            </form>
        @endif
    </div>

    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Publicados</h2>
        @if ($published->isEmpty())
            <p class="content-empty">Nenhum artigo publicado pelo CRM ainda.</p>
        @else
            <table class="content-table">
                <thead>
                    <tr><th>Título</th><th>Artista</th><th>Publicado</th><th>URL</th></tr>
                </thead>
                <tbody>
                    @foreach ($published as $post)
                        <tr>
                            <td><a href="{{ route('content.show', $post) }}">{{ $post->title }}</a></td>
                            <td>{{ $post->artist_slug ?: '—' }}</td>
                            <td>{{ $post->published_at?->format('d/m/Y H:i') ?: '—' }}</td>
                            <td>
                                @if ($post->published_url)
                                    <a href="{{ $post->published_url }}" target="_blank" rel="noopener noreferrer">Abrir</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
@endsection
