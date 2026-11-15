<?php

namespace App\Jobs;

use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\BlogTopic;
use App\Services\Content\ArtistFactSheet;
use App\Services\Content\BlogCatalog;
use App\Services\Content\BlogDraftGenerator;
use App\Services\Content\BlogQualityGate;
use App\Services\Content\BlogReviewer;
use App\Services\Content\ContentException;
use App\Services\Content\ContentMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generates (or regenerates) one article and its Copilot review. The result is
 * always `needs_review`; this job never publishes.
 */
class GenerateBlogPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 540;

    public function __construct(
        public readonly string $topicId,
        public readonly ?string $postId = null,
        public readonly ?string $feedback = null,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue((string) config('content.queue', 'content'));
    }

    public function handle(
        ArtistFactSheet $factSheet,
        BlogDraftGenerator $generator,
        BlogQualityGate $gate,
        BlogReviewer $reviewer,
        BlogCatalog $catalog,
        ContentMailer $mailer,
    ): void {
        $topic = BlogTopic::find($this->topicId);
        if ($topic === null) {
            throw new ContentException('Tópico de conteúdo não encontrado.');
        }

        $post = $this->resolvePost($topic);
        if ($post === null) {
            return;
        }

        $post->forceFill([
            'status' => BlogPost::STATUS_GENERATING,
            'feedback' => $this->feedback,
        ])->save();

        try {
            $facts = $factSheet->fetch((string) $topic->artist_slug);

            $existing = BlogPost::query()
                ->where('status', BlogPost::STATUS_PUBLISHED)
                ->whereKeyNot($post->id)
                ->get(['title', 'slug'])
                ->map(fn (BlogPost $item): array => [
                    'title' => (string) $item->title,
                    'slug' => (string) $item->slug,
                ])
                ->all();

            $existingTitles = array_values(array_filter(array_map(
                fn (array $item): string => (string) ($item['title'] ?? ''),
                $existing
            )));

            $currentBody = $this->feedback ? (string) $post->body_md : null;

            $draft = $generator->generate(
                [
                    'track' => $topic->track,
                    'label' => $topic->label(),
                    'target_keyword' => $topic->target_keyword,
                    'search_intent' => $topic->search_intent,
                    'angle' => $topic->angle,
                    'publish_date' => $topic->publish_date?->toDateString(),
                    'artist_slug' => $topic->artist_slug,
                ],
                $facts,
                $existing,
                $currentBody,
                $this->feedback,
            );

            $draft['target_keyword'] = (string) $topic->target_keyword;
            $catalogSlugs = $catalog->publishedSlugs();
            $draft['body_md'] = $this->finalizeBody($draft, $facts, $existing, $catalogSlugs);

            $gateResult = $gate->check(
                $draft,
                $facts,
                $catalogSlugs,
                $existingTitles,
            );

            if (! $gateResult['pass']) {
                // One repair attempt with the gate errors as feedback.
                $repairFeedback = trim(implode(' ', $gateResult['errors']));
                if ($this->feedback) {
                    $repairFeedback .= "\n\nComentários originais do admin: ".$this->feedback;
                }

                $draft = $generator->generate(
                    [
                        'track' => $topic->track,
                        'label' => $topic->label(),
                        'target_keyword' => $topic->target_keyword,
                        'search_intent' => $topic->search_intent,
                        'angle' => $topic->angle,
                        'publish_date' => $topic->publish_date?->toDateString(),
                        'artist_slug' => $topic->artist_slug,
                    ],
                    $facts,
                    $existing,
                    (string) $draft['body_md'],
                    $repairFeedback,
                );

                $draft['target_keyword'] = (string) $topic->target_keyword;
                $draft['body_md'] = $this->finalizeBody($draft, $facts, $existing, $catalogSlugs);

                $gateResult = $gate->check(
                    $draft,
                    $facts,
                    $catalogSlugs,
                    $existingTitles,
                );
            }

            if (! $gateResult['pass']) {
                $post->forceFill([
                    'status' => BlogPost::STATUS_FAILED,
                    'feedback' => 'O artigo não passou no controle de qualidade: '.implode(' ', $gateResult['errors']),
                    'facts' => $facts,
                ])->save();

                $topic->forceFill(['status' => BlogTopic::STATUS_PLANNED])->save();

                return;
            }

            $review = $reviewer->review($draft, $facts);

            $post->forceFill([
                'title' => $draft['title'],
                'slug' => $draft['slug'],
                'meta_description' => $draft['meta_description'],
                'excerpt' => $draft['excerpt'],
                'body_md' => $draft['body_md'],
                'featured_image' => $draft['featured_image'],
                'featured_image_alt' => $draft['featured_image_alt'],
                'faq' => $draft['faq'],
                'tags' => $draft['tags'],
                'sources' => $draft['sources'],
                'category' => $draft['category'],
                'target_keyword' => $topic->target_keyword,
                'search_intent' => $topic->search_intent,
                'facts' => $facts,
                'ai_review' => $review,
                'ai_score' => $review['score'] ?? null,
                'admin_overview' => $review['overview'] ?? null,
                'model' => $review['model'] ?? config('content.model'),
                'status' => BlogPost::STATUS_NEEDS_REVIEW,
                'feedback' => null,
            ])->save();

            $topic->forceFill(['status' => BlogTopic::STATUS_GENERATED])->save();
            Cache::forget('content:published-blog-slugs');

            $mailer->draftReady($post->refresh());
        } catch (\Throwable $e) {
            $post->forceFill([
                'status' => BlogPost::STATUS_FAILED,
                'feedback' => 'Falha ao gerar o artigo: '.$e->getMessage(),
            ])->save();

            $topic->forceFill(['status' => BlogTopic::STATUS_PLANNED])->save();

            Log::error('Blog generation failed', [
                'topic_id' => $topic->id,
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Guarantee the required internal links without inventing a 404.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $facts
     * @param  array<int, array{title: string, slug: string}>  $existing
     * @param  array<int, string>  $catalogSlugs
     */
    private function finalizeBody(array $draft, array $facts, array $existing, array $catalogSlugs): string
    {
        $body = (string) ($draft['body_md'] ?? '');
        $artistSlug = (string) ($facts['slug'] ?? '');
        $artistName = trim((string) ($facts['display_name'] ?? 'o artista'));
        $artistName = str_replace(['[', ']'], '', $artistName);
        $artistUrl = '/artists/'.$artistSlug;

        $knownSlugs = array_fill_keys($catalogSlugs, true);
        foreach ($existing as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if ($slug !== '') {
                $knownSlugs[$slug] = true;
            }
        }

        // Replace a guessed /blog/<slug> that is not published with the archive.
        $body = (string) preg_replace_callback(
            '#\]\(/blog/([a-z0-9\-]+)\)#u',
            function (array $matches) use ($knownSlugs): string {
                return isset($knownSlugs[$matches[1]]) ? $matches[0] : '](/blog)';
            },
            $body
        );

        $links = $this->internalLinks($body);

        if ($artistSlug !== '' && ! in_array($artistUrl, $links, true) && ! str_contains($body, $artistUrl)) {
            $body .= "\n\n## Conheça o artista\n\n[{$artistName}]({$artistUrl}) atende com hora marcada e apresenta o portfólio completo na página do artista.";
            $links[] = $artistUrl;
        }

        if (count($links) < 2) {
            $related = $existing[0] ?? null;
            if (is_array($related) && ! empty($related['slug'])) {
                $title = str_replace(['[', ']'], '', (string) ($related['title'] ?? 'Leia também'));
                $body .= "\n\n## Leia também\n\n[{$title}](/blog/{$related['slug']})";
            } else {
                $body .= "\n\n## Leia também\n\nVeja [todos os artigos do blog](/blog).";
            }
        }

        return $body;
    }

    /**
     * @return array<int, string>
     */
    private function internalLinks(string $body): array
    {
        if (preg_match_all('/\[[^\]]+\]\((\/[^)\s]+)\)/u', $body, $matches)) {
            return array_values(array_unique($matches[1]));
        }

        return [];
    }

    private function resolvePost(BlogTopic $topic): ?BlogPost
    {
        if ($this->postId !== null) {
            $post = BlogPost::find($this->postId);
            if ($post === null) {
                throw new ContentException('Artigo não encontrado.');
            }

            if ($this->feedback !== null && trim((string) $post->body_md) !== '') {
                BlogPostRevision::create([
                    'blog_post_id' => $post->id,
                    'version' => $post->nextRevisionNumber(),
                    'body_md' => $post->body_md,
                    'ai_review' => $post->ai_review ?? [],
                    'feedback' => $this->feedback,
                    'created_by' => $this->userId,
                    'created_at' => now(),
                ]);
            }

            return $post;
        }

        $existing = BlogPost::query()
            ->where('blog_topic_id', $topic->id)
            ->latest('created_at')
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, [
                BlogPost::STATUS_GENERATING,
                BlogPost::STATUS_NEEDS_REVIEW,
                BlogPost::STATUS_APPROVED,
                BlogPost::STATUS_PUBLISHING,
                BlogPost::STATUS_PUBLISHED,
            ], true)) {
                // Already in the human review or publish path; never overwrite it.
                return null;
            }

            $existing->forceFill([
                'artist_id' => $topic->artist_id,
                'artist_slug' => $topic->artist_slug,
                'track' => $topic->track,
                'target_keyword' => $topic->target_keyword,
                'search_intent' => $topic->search_intent,
            ])->save();

            return $existing;
        }

        return BlogPost::create([
            'blog_topic_id' => $topic->id,
            'artist_id' => $topic->artist_id,
            'artist_slug' => $topic->artist_slug,
            'track' => $topic->track,
            'target_keyword' => $topic->target_keyword,
            'search_intent' => $topic->search_intent,
            'status' => BlogPost::STATUS_GENERATING,
        ]);
    }
}
