<?php

namespace App\Services\Content;

use App\Services\Agent\Json;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a topic + public artist fact sheet into a structured article draft.
 */
class BlogDraftGenerator
{
    public function __construct(private readonly OpenRouterClient $ai) {}

    /**
     * @param  array<string, mixed>  $topic
     * @param  array<string, mixed>  $facts
     * @param  array<int, array{title: string, slug: string}>  $existing
     * @return array<string, mixed>
     */
    public function generate(
        array $topic,
        array $facts,
        array $existing = [],
        ?string $currentBody = null,
        ?string $feedback = null,
    ): array {
        $system = (string) file_get_contents(resource_path('prompts/blog-draft.md'));

        $context = [
            'TÓPICO' => $topic,
            'ARTISTA' => $this->trimFacts($facts),
            'POSTS EXISTENTES' => $existing,
        ];

        if ($currentBody !== null && $currentBody !== '') {
            $context['VERSÃO ATUAL'] = $currentBody;
        }
        if ($feedback !== null && $feedback !== '') {
            $context['COMENTÁRIOS DO ADMIN'] = $feedback;
        }

        $content = $this->ai->complete(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => Json::string($context)],
            ],
            (string) config('content.model'),
            [
                'temperature' => 0.55,
                'max_tokens' => (int) config('content.max_tokens', 6000),
                '_timeout' => (int) config('content.timeout', 120),
            ],
        );

        $parsed = Json::parse($content);
        if ($parsed === []) {
            Log::warning('Blog draft JSON parse failed', [
                'model' => (string) config('content.model'),
                'length' => strlen($content),
                'head' => mb_substr($content, 0, 1200),
                'tail' => mb_substr($content, -800),
            ]);

            throw new ContentException('O modelo não devolveu um JSON de artigo válido.');
        }

        return $this->normalize($parsed, $facts);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function normalize(array $parsed, array $facts): array
    {
        $title = trim((string) ($parsed['title'] ?? ''));
        $body = trim((string) ($parsed['body_md'] ?? ''));
        $slug = Str::slug((string) ($parsed['slug'] ?? $title));

        if ($title === '') {
            throw new ContentException('O artigo gerado não tem título.');
        }
        if ($body === '') {
            throw new ContentException('O artigo gerado não tem corpo.');
        }

        // The LP renders the frontmatter title as the only H1.
        $body = (string) preg_replace('/\A#\s+.+\R+/u', '', $body);

        $faq = [];
        foreach ((array) ($parsed['faq'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $faq[] = [
                'question' => mb_substr($question, 0, 220),
                'answer' => mb_substr($answer, 0, 900),
            ];
        }

        $tags = [];
        foreach ((array) ($parsed['tags'] ?? []) as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = mb_substr($tag, 0, 40);
            }
        }

        $sources = [];
        foreach ((array) ($parsed['sources'] ?? []) as $source) {
            if (is_string($source) && trim($source) !== '') {
                $sources[] = trim($source);
            }
        }

        $image = trim((string) ($parsed['featured_image'] ?? ''));
        $portfolioUrls = array_values(array_filter(array_map(
            fn ($item) => is_array($item) ? (string) ($item['src'] ?? '') : '',
            (array) ($facts['portfolio'] ?? [])
        )));

        if ($image === '' || ($portfolioUrls !== [] && ! in_array($image, $portfolioUrls, true))) {
            $image = $portfolioUrls[0] ?? $image;
        }

        return [
            'title' => mb_substr($title, 0, 120),
            'slug' => mb_substr($slug, 0, 100),
            'meta_description' => mb_substr(trim((string) ($parsed['meta_description'] ?? '')), 0, 180),
            'excerpt' => mb_substr(trim((string) ($parsed['excerpt'] ?? '')), 0, 320),
            'body_md' => $body,
            'faq' => array_slice($faq, 0, 8),
            'tags' => array_values(array_unique(array_slice($tags, 0, 8))),
            'category' => mb_substr(trim((string) ($parsed['category'] ?? 'Tatuagem')), 0, 60),
            'featured_image' => $image,
            'featured_image_alt' => mb_substr(trim((string) ($parsed['featured_image_alt'] ?? $title)), 0, 180),
            'sources' => array_slice($sources, 0, 8),
        ];
    }

    /**
     * Keep the prompt focused on facts the article may use.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function trimFacts(array $facts): array
    {
        $facts['bio_plain'] = mb_substr((string) ($facts['bio_plain'] ?? ''), 0, 2500);
        $facts['portfolio'] = array_slice((array) ($facts['portfolio'] ?? []), 0, 10);
        $facts['before_after'] = array_slice((array) ($facts['before_after'] ?? []), 0, 5);
        $facts['testimonials'] = array_slice((array) ($facts['testimonials'] ?? []), 0, 5);
        $facts['faq'] = array_slice((array) ($facts['faq'] ?? []), 0, 8);

        return $facts;
    }
}
