@extends('layouts.app')

@section('title', $post->title ?: 'Artigo')

@section('content')
<style>
    .content-chip { display: inline-block; font-size: .66rem; letter-spacing: .4px; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
    .content-chip--ready { color: var(--gold); border-color: var(--gold); }
    .content-chip--failed { color: var(--danger, #d05a5a); border-color: var(--danger, #d05a5a); }
    .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .content-stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
    .content-stat strong { display: block; font-size: 1.35rem; color: var(--gold); line-height: 1.1; }
    .content-stat span { display: block; color: var(--muted); font-size: .66rem; text-transform: uppercase; letter-spacing: .6px; margin-top: 5px; }
    .content-table { width: 100%; border-collapse: collapse; }
    .content-table th, .content-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .content-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .content-check { display: flex; gap: 10px; align-items: flex-start; padding: 9px 0; border-bottom: 1px solid var(--border); }
    .content-check:last-child { border-bottom: 0; }
    .content-check-status { font-size: .66rem; text-transform: uppercase; letter-spacing: .5px; min-width: 52px; }
    .content-check-status--pass { color: var(--gold); }
    .content-check-status--warn { color: #e0b85a; }
    .content-check-status--fail { color: var(--danger, #d05a5a); }
    .content-issue { border: 1px solid var(--border); border-left: 4px solid var(--border); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
    .content-issue--info { border-left-color: #7a7a7a; }
    .content-issue--warning { border-left-color: var(--gold); }
    .content-issue--critical { border-left-color: var(--danger, #d05a5a); }
    .content-preview { background: #0A0A0A; border: 1px solid var(--border); border-radius: 12px; padding: 24px; line-height: 1.7; }
    .content-preview h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; margin: 26px 0 10px; }
    .content-preview h3 { font-size: 1.05rem; margin: 20px 0 8px; }
    .content-preview p { margin: 0 0 12px; color: var(--ink); }
    .content-preview a { color: var(--gold); }
    .content-preview img { max-width: 100%; border-radius: 10px; }
    .content-form label { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 5px; }
    .content-form textarea { width: 100%; min-height: 100px; background: var(--bg); color: var(--ink); border: 1px solid var(--border); border-radius: 8px; padding: 10px; font-family: inherit; font-size: .85rem; resize: vertical; }
    .content-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .content-kv { display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; font-size: .85rem; }
    .content-kv dt { color: var(--muted); }
    .content-kv dd { margin: 0; word-break: break-word; }
    .content-revision { border-bottom: 1px solid var(--border); padding: 10px 0; }
    .content-revision pre { white-space: pre-wrap; font-size: .78rem; max-height: 260px; overflow: auto; background: var(--bg); padding: 10px; border-radius: 8px; }
</style>

<a href="{{ route('content.index') }}" class="back">&larr; Conteúdo</a>

<div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <div>
        <h1 class="page-title" style="margin-bottom:4px;">{{ $post->title ?: 'Artigo em geração' }}</h1>
        <div class="meta">
            <span class="content-chip content-chip--{{ $post->statusTone() }}">{{ $post->statusLabel() }}</span>
            · {{ $post->artist_slug ?: 'sem artista' }}
            · {{ $post->target_keyword ?: 'sem palavra-chave' }}
            @if ($post->ai_score !== null) · score {{ $post->ai_score }}/100 @endif
            @if ($post->model) · {{ $post->model }} @endif
        </div>
    </div>
    <a href="{{ url()->current() }}" class="btn btn--ghost">Atualizar</a>
</div>

@if ($post->status === 'failed' && $post->feedback)
    <div class="flash error" style="margin-bottom:16px;">{{ $post->feedback }}</div>
@endif

@if ($post->admin_overview)
    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Resumo para aprovação</h2>
        <p style="line-height:1.7; margin:0;">{{ $post->admin_overview }}</p>
    </div>
@endif

@if (! empty($post->facts))
    <details class="panel" style="margin-bottom:18px;">
        <summary style="cursor:pointer; font-weight:600;">
            Ficha do artista usada na geração
            @if ($post->artist_id)
                <a href="{{ route('artists.landing-page', $post->artist_id) }}" style="margin-left:8px; font-weight:400; font-size:.78rem;" onclick="event.stopPropagation()">ver landing page atual</a>
            @endif
        </summary>
        <div style="margin-top:14px;">
            @include('partials.artist-facts', ['facts' => $post->facts])
        </div>
    </details>
@endif

@php($review = $post->ai_review ?? [])
@php($previewImage = $post->featured_image
    ? (str_starts_with((string) $post->featured_image, '/')
        ? rtrim((string) config('content.lp.base_url', 'https://vaif.com.br'), '/') . $post->featured_image
        : $post->featured_image)
    : '')

@if (! empty($review))
    <div class="panel" style="margin-bottom:18px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <h2 class="section-title" style="margin-top:0;">Revisão do Copiloto</h2>
            <span class="meta">
                Score {{ $review['score'] ?? $post->ai_score ?? '—' }}/100
                · veredito {{ $review['verdict'] ?? 'review' }}
            </span>
        </div>

        @if (! empty($review['checks']))
            <div style="margin-bottom:16px;">
                @foreach ($review['checks'] as $check)
                    <div class="content-check">
                        <span class="content-check-status content-check-status--{{ $check['status'] ?? 'warn' }}">{{ $check['status'] ?? 'warn' }}</span>
                        <div>
                            <strong>{{ $check['name'] ?? '' }}</strong>
                            @if (! empty($check['note']))
                                <div class="meta">{{ $check['note'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if (! empty($review['issues']))
            @foreach ($review['issues'] as $issue)
                <div class="content-issue content-issue--{{ $issue['severity'] ?? 'info' }}">
                    <div class="meta" style="text-transform:uppercase; letter-spacing:.6px;">{{ $issue['severity'] ?? 'info' }}</div>
                    <h3 style="margin:2px 0 6px;">{{ $issue['title'] ?? '' }}</h3>
                    <div>{{ $issue['body'] ?? '' }}</div>
                    @if (! empty($issue['suggested_fix']))
                        <div class="meta" style="margin-top:6px;"><strong style="color:var(--gold);">Correção sugerida:</strong> {{ $issue['suggested_fix'] }}</div>
                    @endif
                </div>
            @endforeach
        @endif

        @if (! empty($review['risk_flags']))
            <div class="meta" style="margin-top:10px;"><strong style="color:var(--gold);">Riscos:</strong> {{ implode(' · ', $review['risk_flags']) }}</div>
        @endif

        @if (! empty($review['suggested_edits']))
            <div style="margin-top:14px;">
                <h3 style="margin:0 0 8px;">Edições sugeridas</h3>
                <table class="content-table">
                    <thead><tr><th>Trecho</th><th>Substituir por</th><th>Motivo</th></tr></thead>
                    <tbody>
                        @foreach ($review['suggested_edits'] as $edit)
                            <tr>
                                <td><code>{{ $edit['find'] ?? '' }}</code></td>
                                <td>{{ $edit['replace'] ?? '' }}</td>
                                <td class="meta">{{ $edit['reason'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

<div class="panel" style="margin-bottom:18px;">
    <h2 class="section-title" style="margin-top:0;">Prévia do artigo</h2>
    @if ($previewImage)
        <img src="{{ $previewImage }}" alt="{{ $post->featured_image_alt }}" style="max-width:100%; border-radius:12px; margin-bottom:16px;">
    @endif
    <div class="content-preview">{!! $preview !!}</div>
</div>

<div class="panel" style="margin-bottom:18px;">
    <h2 class="section-title" style="margin-top:0;">Ficha técnica</h2>
    <dl class="content-kv">
        <dt>Slug</dt><dd><code>{{ $post->slug ?: '—' }}</code></dd>
        <dt>Meta description</dt><dd>{{ $post->meta_description ?: '—' }}</dd>
        <dt>Track</dt><dd>{{ $post->topic?->label() ?: ($post->track ?: '—') }}</dd>
        <dt>Data planejada</dt><dd>{{ $post->topic?->publish_date?->format('d/m/Y') ?? '—' }}</dd>
        <dt>Intenção</dt><dd>{{ $post->search_intent ?: '—' }}</dd>
        <dt>Tags</dt><dd>{{ implode(', ', $post->tags ?? []) ?: '—' }}</dd>
        <dt>FAQs</dt><dd>{{ count($post->faq ?? []) }}</dd>
        <dt>Imagem</dt><dd>{{ $post->featured_image ?: '—' }}</dd>
        <dt>Aprovado por</dt><dd>{{ $post->approver?->name ?? '—' }}{{ $post->approved_at ? ' · ' . $post->approved_at->format('d/m/Y H:i') : '' }}</dd>
        <dt>Publicado</dt><dd>{{ $post->published_at?->format('d/m/Y H:i') ?: '—' }}</dd>
        @if ($post->published_url)
            <dt>URL</dt><dd><a href="{{ $post->published_url }}" target="_blank" rel="noopener noreferrer">{{ $post->published_url }}</a></dd>
        @endif
        @if ($post->github_commit_sha)
            <dt>Commit</dt><dd><code>{{ $post->github_commit_sha }}</code></dd>
        @endif
    </dl>
</div>

@if (! in_array($post->status, ['published', 'publishing', 'approved', 'generating'], true))
    <div class="panel" style="margin-bottom:18px;">
        <h2 class="section-title" style="margin-top:0;">Comentários para regeneração</h2>
        <p class="meta" style="margin-top:0;">
            Escreva o que o Copiloto deve ajustar. Ele reescreve o artigo com a ficha do artista e guarda a versão anterior no histórico.
        </p>
        <form method="post" action="{{ route('content.regenerate', $post) }}" class="content-form">
            @csrf
            <label for="feedback">O que ajustar?</label>
            <textarea id="feedback" name="feedback" required placeholder="Ex.: encurtar a introdução, falar mais do estúdio no Jardim Santa Rita, remover a menção a preço e destacar coberturas de braço.">{{ $post->feedback }}</textarea>
            <div class="content-actions">
                <button class="btn" type="submit">Regenerar com comentários</button>
            </div>
        </form>
    </div>
@endif

<div class="panel" style="margin-bottom:18px;">
    <h2 class="section-title" style="margin-top:0;">Ações</h2>
    <div class="content-actions">
        @if ($post->status === 'needs_review')
            <form method="post" action="{{ route('content.approve', $post) }}">
                @csrf
                <button class="btn" type="submit">Aprovar e publicar</button>
            </form>
        @endif

        @if ($post->status === 'generating' && $post->body_md)
            <form method="post" action="{{ route('content.reset', $post) }}">
                @csrf
                <button class="btn btn--ghost" type="submit" onclick="return confirm('Destravar este artigo? Use apenas se a geração travou.')">Destravar para revisão</button>
            </form>
        @endif

        @if (in_array($post->status, ['approved', 'publishing'], true) || ($post->status === 'failed' && $post->github_commit_sha))
            <form method="post" action="{{ route('content.publish', $post) }}">
                @csrf
                <button class="btn" type="submit">Tentar publicar novamente</button>
            </form>
        @endif

        @if ($post->status === 'published' && $post->published_url)
            <a class="btn btn--ghost" href="{{ $post->published_url }}" target="_blank" rel="noopener noreferrer">Abrir no blog</a>
        @endif

        @if (! in_array($post->status, ['published', 'publishing', 'generating'], true))
            <form method="post" action="{{ route('content.reject', $post) }}">
                @csrf
                <button class="btn btn--ghost" type="submit" onclick="return confirm('Rejeitar este artigo?')">Rejeitar</button>
            </form>
        @endif
    </div>
</div>

@if ($post->revisions->isNotEmpty())
    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Histórico de versões</h2>
        @foreach ($post->revisions as $revision)
            <div class="content-revision">
                <div class="meta">
                    v{{ $revision->version }}
                    · {{ $revision->created_at?->format('d/m/Y H:i') }}
                    @if ($revision->editor) · {{ $revision->editor->name }} @endif
                </div>
                @if ($revision->feedback)
                    <p style="margin:6px 0;"><strong style="color:var(--gold);">Comentário:</strong> {{ $revision->feedback }}</p>
                @endif
                <details>
                    <summary class="meta">Ver versão anterior</summary>
                    <pre>{{ $revision->body_md }}</pre>
                </details>
            </div>
        @endforeach
    </div>
@endif
@endsection
