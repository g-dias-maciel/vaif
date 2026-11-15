<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Existing public blog URLs. Used by the quality gate to avoid publishing a
 * slug that is already live outside the CRM.
 */
class BlogCatalog
{
    /**
     * @return array<int, string>
     */
    public function publishedSlugs(): array
    {
        $key = 'content:published-blog-slugs';
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $ttl = (int) config('content.catalog.cache_seconds', 900);
        $url = (string) config('content.catalog.sitemap_url');
        if ($url === '') {
            return [];
        }

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Blog catalog sitemap fetch failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $slugs = [];
        if (preg_match_all('#<loc>\s*https?://[^<]+/blog/([a-z0-9\-]+)\s*</loc>#i', $response->body(), $matches)) {
            foreach ($matches[1] as $slug) {
                $slugs[] = $slug;
            }
        }

        $slugs = array_values(array_unique($slugs));
        Cache::put($key, $slugs, $ttl);

        return $slugs;
    }
}
