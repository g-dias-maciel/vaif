<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateBlogPost;
use App\Jobs\PublishBlogPost;
use App\Models\Artist;
use App\Models\BlogPost;
use App\Models\BlogTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Staff-only content cockpit: weekly topic calendar, AI drafts, Copilot review
 * and the human approval that publishes to the LP.
 */
class ContentController extends Controller
{
    public function index()
    {
        $schemaReady = $this->schemaReady();

        $upcoming = $schemaReady
            ? BlogTopic::query()
                ->where('status', BlogTopic::STATUS_PLANNED)
                ->orderBy('publish_date')
                ->limit(12)
                ->get()
            : collect();

        $reviews = $schemaReady
            ? BlogPost::query()
                ->with(['topic', 'artist'])
                ->where('status', BlogPost::STATUS_NEEDS_REVIEW)
                ->orderByDesc('updated_at')
                ->get()
            : collect();

        $inFlight = $schemaReady
            ? BlogPost::query()
                ->with(['topic', 'artist'])
                ->whereIn('status', [
                    BlogPost::STATUS_GENERATING,
                    BlogPost::STATUS_APPROVED,
                    BlogPost::STATUS_PUBLISHING,
                    BlogPost::STATUS_FAILED,
                ])
                ->orderByDesc('updated_at')
                ->limit(12)
                ->get()
            : collect();

        $published = $schemaReady
            ? BlogPost::query()
                ->where('status', BlogPost::STATUS_PUBLISHED)
                ->orderByDesc('published_at')
                ->limit(12)
                ->get()
            : collect();

        $artists = $schemaReady
            ? Artist::query()
                ->with('settings')
                ->whereHas('settings', fn ($query) => $query->whereNotNull('landing_page_slug'))
                ->orderBy('name')
                ->get()
            : collect();

        $nextTopic = $schemaReady
            ? BlogTopic::query()
                ->where('status', BlogTopic::STATUS_PLANNED)
                ->whereDate('publish_date', '<=', now()->toDateString())
                ->orderBy('publish_date')
                ->first()
            : null;

        return view('content.index', [
            'schemaReady' => $schemaReady,
            'upcoming' => $upcoming,
            'reviews' => $reviews,
            'inFlight' => $inFlight,
            'published' => $published,
            'artists' => $artists,
            'nextTopic' => $nextTopic,
        ]);
    }

    public function show(BlogPost $post)
    {
        $post->load(['topic', 'artist', 'revisions', 'approver']);

        $preview = Str::markdown((string) $post->body_md, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('content.show', [
            'post' => $post,
            'preview' => $preview,
        ]);
    }

    public function generate(Request $request)
    {
        if (! $this->schemaReady()) {
            return back()->with('error', 'A migração de conteúdo ainda não foi aplicada.');
        }

        $data = $request->validate([
            'topic_id' => ['nullable', 'uuid', 'exists:blog_topics,id'],
        ]);

        $topic = isset($data['topic_id'])
            ? BlogTopic::find($data['topic_id'])
            : BlogTopic::query()
                ->where('status', BlogTopic::STATUS_PLANNED)
                ->whereDate('publish_date', '<=', now()->toDateString())
                ->orderBy('publish_date')
                ->first();

        if ($topic === null) {
            return back()->with('error', 'Não há tópico planejado para gerar agora. Crie um tópico no calendário.');
        }

        $pending = BlogPost::query()
            ->where('blog_topic_id', $topic->id)
            ->whereIn('status', [
                BlogPost::STATUS_GENERATING,
                BlogPost::STATUS_NEEDS_REVIEW,
                BlogPost::STATUS_APPROVED,
                BlogPost::STATUS_PUBLISHING,
                BlogPost::STATUS_PUBLISHED,
            ])
            ->exists();

        if ($pending) {
            return back()->with('error', 'Este tópico já tem um artigo em revisão, aprovado ou publicado.');
        }

        if ($topic->status !== BlogTopic::STATUS_PLANNED) {
            return back()->with('error', 'Este tópico já foi gerado ou está na fila.');
        }

        if (empty($topic->artist_slug)) {
            return back()->with('error', 'O artista do tópico não tem slug de landing page configurado.');
        }

        $topic->forceFill(['status' => BlogTopic::STATUS_GENERATED])->save();

        try {
            GenerateBlogPost::dispatch($topic->id, null, null, auth()->id());
        } catch (\Throwable $e) {
            $topic->forceFill(['status' => BlogTopic::STATUS_PLANNED])->save();

            return back()->with('error', 'Não foi possível enfileirar a geração: '.$e->getMessage());
        }

        return back()->with('status', "Geração iniciada para “{$topic->target_keyword}”. O artigo aparece aqui quando o Copiloto terminar.");
    }

    public function storeTopic(Request $request)
    {
        if (! $this->schemaReady()) {
            return back()->with('error', 'A migração de conteúdo ainda não foi aplicada.');
        }

        $data = $request->validate([
            'artist_id' => ['required', 'uuid', 'exists:artists,id'],
            'publish_date' => ['required', 'date'],
            'track' => ['required', 'in:local_guide,spotlight,specialty_guide,case_study,educational'],
            'target_keyword' => ['required', 'string', 'max:120'],
            'search_intent' => ['nullable', 'string', 'max:80'],
            'angle' => ['nullable', 'string', 'max:500'],
        ]);

        $artist = Artist::with('settings')->findOrFail($data['artist_id']);
        $slug = (string) ($artist->settings?->landing_page_slug ?? '');

        if ($slug === '') {
            return back()->with('error', 'Configure o slug da landing page do artista antes de criar o tópico.');
        }

        $duplicate = BlogTopic::query()
            ->where('artist_id', $artist->id)
            ->where('target_keyword', $data['target_keyword'])
            ->whereIn('status', [
                BlogTopic::STATUS_PLANNED,
                BlogTopic::STATUS_GENERATED,
                BlogTopic::STATUS_PUBLISHED,
            ])
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'Já existe um tópico ativo com essa palavra-chave para este artista.');
        }

        BlogTopic::create([
            'publish_date' => $data['publish_date'],
            'artist_id' => $artist->id,
            'artist_slug' => $slug,
            'track' => $data['track'],
            'target_keyword' => $data['target_keyword'],
            'search_intent' => $data['search_intent'] ?? null,
            'angle' => $data['angle'] ?? null,
            'status' => BlogTopic::STATUS_PLANNED,
        ]);

        return back()->with('status', 'Tópico adicionado ao calendário.');
    }

    public function approve(BlogPost $post)
    {
        if ($post->status !== BlogPost::STATUS_NEEDS_REVIEW) {
            return back()->with('error', 'Este artigo não está aguardando revisão.');
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ])->save();

        try {
            PublishBlogPost::dispatch($post->id, auth()->id());
        } catch (\Throwable $e) {
            $post->forceFill([
                'status' => BlogPost::STATUS_NEEDS_REVIEW,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            return back()->with('error', 'Não foi possível enfileirar a publicação: '.$e->getMessage());
        }

        return back()->with('status', 'Artigo aprovado. O commit e o deploy foram iniciados.');
    }

    public function regenerate(Request $request, BlogPost $post)
    {
        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:2000'],
        ]);

        if (in_array($post->status, [
            BlogPost::STATUS_PUBLISHED,
            BlogPost::STATUS_PUBLISHING,
            BlogPost::STATUS_APPROVED,
            BlogPost::STATUS_GENERATING,
        ], true)) {
            return back()->with('error', 'Artigos em geração, aprovados, publicados ou em publicação não podem ser regenerados.');
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_GENERATING,
            'feedback' => $data['feedback'],
        ])->save();

        try {
            GenerateBlogPost::dispatch(
                $post->blog_topic_id,
                $post->id,
                $data['feedback'],
                auth()->id(),
            );
        } catch (\Throwable $e) {
            $post->forceFill([
                'status' => BlogPost::STATUS_NEEDS_REVIEW,
                'feedback' => null,
            ])->save();

            return back()->with('error', 'Não foi possível enfileirar a regeneração: '.$e->getMessage());
        }

        return back()->with('status', 'Regeneração iniciada. A versão anterior ficou no histórico.');
    }

    public function reject(Request $request, BlogPost $post)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($post->status, [
            BlogPost::STATUS_PUBLISHED,
            BlogPost::STATUS_PUBLISHING,
            BlogPost::STATUS_GENERATING,
        ], true)) {
            return back()->with('error', 'Um artigo em geração, publicado ou em publicação não pode ser rejeitado por aqui.');
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_REJECTED,
            'feedback' => $data['reason'] ?? 'Rejeitado pelo admin.',
        ])->save();

        return back()->with('status', 'Artigo rejeitado.');
    }

    public function reset(BlogPost $post)
    {
        if ($post->status !== BlogPost::STATUS_GENERATING) {
            return back()->with('error', 'Só um artigo travado em geração pode ser destravado.');
        }

        if (empty($post->body_md)) {
            return back()->with('error', 'O artigo ainda não tem corpo; regenere em vez de destravar.');
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_NEEDS_REVIEW,
            'feedback' => null,
        ])->save();

        return back()->with('status', 'Artigo destravado para revisão.');
    }

    public function retryPublish(BlogPost $post)
    {
        if (! in_array($post->status, [
            BlogPost::STATUS_APPROVED,
            BlogPost::STATUS_PUBLISHING,
        ], true) && ! ($post->status === BlogPost::STATUS_FAILED && $post->github_commit_sha)) {
            return back()->with('error', 'Este artigo não está pronto para uma nova tentativa de publicação.');
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_APPROVED,
            'approved_by' => $post->approved_by ?? auth()->id(),
            'approved_at' => $post->approved_at ?? now(),
        ])->save();

        try {
            PublishBlogPost::dispatch($post->id, auth()->id());
        } catch (\Throwable $e) {
            $post->forceFill(['status' => BlogPost::STATUS_FAILED])->save();

            return back()->with('error', 'Não foi possível enfileirar a publicação: '.$e->getMessage());
        }

        return back()->with('status', 'Nova tentativa de publicação iniciada.');
    }

    private function schemaReady(): bool
    {
        try {
            return Schema::hasTable('blog_posts') && Schema::hasTable('blog_topics');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
