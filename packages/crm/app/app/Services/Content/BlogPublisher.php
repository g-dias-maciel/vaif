<?php

namespace App\Services\Content;

use App\Models\BlogPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes an approved article: markdown → GitHub → Coolify → live URL.
 *
 * The database only moves to `published` after the public URL responds and
 * contains the expected schema. A failed verification leaves the draft and the
 * commit SHA visible so an admin can retry.
 */
class BlogPublisher
{
    public function __construct(private readonly GitHubPublisher $github) {}

    public function publish(BlogPost $post): BlogPost
    {
        $post->loadMissing('topic');

        if (! in_array($post->status, [
            BlogPost::STATUS_APPROVED,
            BlogPost::STATUS_FAILED,
            BlogPost::STATUS_PUBLISHING,
        ], true)) {
            throw new ContentException('Este artigo não está pronto para publicar.');
        }

        if (empty($post->slug) || empty($post->body_md)) {
            throw new ContentException('O artigo está sem slug ou sem corpo.');
        }

        if ($post->status === BlogPost::STATUS_FAILED && ! $post->github_commit_sha) {
            throw new ContentException('Este artigo falhou na geração; regenere antes de publicar.');
        }

        $post->forceFill(['status' => BlogPost::STATUS_PUBLISHING])->save();

        $path = trim((string) config('content.github.path', 'packages/lp/content/blog'), '/')
            .'/'.$post->slug.'.md';

        $markdown = $this->buildMarkdown($post);

        $sha = $this->github->put(
            $path,
            $markdown,
            'content(blog): publish '.$post->slug,
        );

        $post->forceFill(['github_commit_sha' => $sha])->save();

        $url = rtrim((string) config('content.lp.base_url'), '/').'/blog/'.$post->slug;
        $this->verify($url, (string) $post->slug);

        $this->pingIndexNow($url);

        $post->forceFill([
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_url' => $url,
            'published_at' => now(),
            'verified_at' => now(),
        ])->save();

        if ($post->topic !== null) {
            $post->topic->forceFill(['status' => 'published'])->save();
        }

        Cache::forget('content:published-blog-slugs');

        return $post->refresh();
    }

    public function buildMarkdown(BlogPost $post): string
    {
        $post->loadMissing('topic');

        $topicDate = $post->topic?->publish_date?->toDateString();
        $today = now()->toDateString();
        $date = ($topicDate !== null && $topicDate <= $today) ? $topicDate : $today;
        $updated = $today;

        $faqJson = json_encode(
            $post->faq ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]';

        $tags = is_array($post->tags) ? implode(', ', $post->tags) : '';

        $lines = [
            '---',
            'title: '.$this->yaml((string) $post->title),
            'date: '.$date,
            'updated: '.$updated,
            'slug: '.$this->yaml((string) $post->slug),
            'description: '.$this->yaml((string) $post->meta_description),
            'author: '.$this->yaml((string) config('content.author', 'Equipe VAIF')),
            'author_url: '.$this->yaml((string) config('content.author_url', 'https://vaif.com.br')),
        ];

        if ($post->artist_slug) {
            $lines[] = 'artist_slug: '.$this->yaml((string) $post->artist_slug);
        }
        if ($post->featured_image) {
            $lines[] = 'featured_image: '.$this->yaml((string) $post->featured_image);
        }
        if ($post->featured_image_alt ?? false) {
            $lines[] = 'featured_image_alt: '.$this->yaml((string) $post->featured_image_alt);
        }
        if ($tags !== '') {
            $lines[] = 'tags: '.$this->yaml($tags);
        }
        if ($post->category) {
            $lines[] = 'category: '.$this->yaml((string) $post->category);
        }

        $lines[] = 'faq_json: '.$faqJson;

        $sources = is_array($post->sources) ? $post->sources : [];
        if ($sources !== []) {
            $lines[] = 'sources_json: '.(json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
        }

        $lines[] = 'draft: false';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = trim((string) $post->body_md);
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function yaml(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = trim($value);

        // The LP frontmatter parser splits on the first colon; a value that is
        // only delimiters would break the block. Quote it in that rare case.
        if ($value === '---' || $value === '') {
            return '""';
        }

        return $value;
    }

    private function verify(string $url, string $slug): void
    {
        $attempts = max(1, (int) config('content.verify.attempts', 20));
        $sleep = max(1, (int) config('content.verify.sleep_seconds', 15));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(10)->get($url);
                $body = $response->body();

                if ($response->successful()
                    && str_contains($body, 'BlogPosting')
                    && str_contains($body, $slug)) {
                    return;
                }
            } catch (\Throwable $e) {
                Log::info('Blog publish verification attempt failed', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $attempts) {
                sleep($sleep);
            }
        }

        throw new ContentException(
            'O commit foi enviado, mas a página não ficou disponível a tempo. '.
            'O deploy pode ainda estar em andamento; use "Tentar publicar novamente".'
        );
    }

    private function pingIndexNow(string $url): void
    {
        $endpoint = (string) config('content.indexnow.endpoint');
        $key = (string) config('content.indexnow.key');
        $keyLocation = (string) config('content.indexnow.key_location');
        $host = (string) parse_url((string) config('content.lp.base_url'), PHP_URL_HOST);

        if ($endpoint === '' || $key === '' || $host === '') {
            return;
        }

        try {
            $response = Http::timeout(10)->post($endpoint, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => $keyLocation,
                'urlList' => [$url],
            ]);

            if (! $response->successful()) {
                Log::warning('IndexNow ping failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('IndexNow ping failed', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
