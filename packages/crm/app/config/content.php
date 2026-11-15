<?php

$lpBase = rtrim((string) (env('LP_BASE_URL') ?: 'https://vaif.com.br'), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly blog content workflow
    |--------------------------------------------------------------------------
    |
    | The CRM drafts, the AI copilot reviews, a staff user approves, and the
    | publisher commits the final markdown to the LP repository. The LP reads
    | the file on its next deploy; it never writes content at runtime.
    |
    */

    'enabled' => (bool) env('CONTENT_ENABLED', true),

    'timezone' => env('CONTENT_TIMEZONE', 'America/Sao_Paulo'),

    // Laravel's weeklyOn(): 1 = Monday.
    'weekly_day' => (int) env('CONTENT_WEEKLY_DAY', 1),
    'weekly_time' => env('CONTENT_WEEKLY_TIME', '08:00'),

    'queue' => env('CONTENT_QUEUE', 'content'),

    'model' => env('CONTENT_MODEL') ?: env('AGENT_MODEL', 'anthropic/claude-haiku-5.5'),
    'review_model' => env('CONTENT_REVIEW_MODEL') ?: (env('CONTENT_MODEL') ?: env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),

    'max_tokens' => (int) env('CONTENT_MAX_TOKENS', 12000),
    'review_max_tokens' => (int) env('CONTENT_REVIEW_MAX_TOKENS', 6000),
    'timeout' => (int) env('CONTENT_AI_TIMEOUT', 150),

    'min_words' => (int) env('CONTENT_MIN_WORDS', 900),
    'max_words' => (int) env('CONTENT_MAX_WORDS', 1800),
    'min_faqs' => (int) env('CONTENT_MIN_FAQS', 4),
    'min_internal_links' => (int) env('CONTENT_MIN_INTERNAL_LINKS', 2),

    'author' => env('CONTENT_AUTHOR') ?: 'Equipe VAIF',
    'author_url' => env('CONTENT_AUTHOR_URL') ?: 'https://vaif.com.br',

    'lp' => [
        'base_url' => $lpBase,
        'artist_api_url' => (string) env(
            'LP_ARTIST_API_URL',
            $lpBase.'/api/artists/profile.php'
        ),
        'artist_api_key' => env('LP_ARTIST_API_KEY'),
        'timeout' => (int) env('LP_ARTIST_API_TIMEOUT', 20),
    ],

    'github' => [
        'token' => env('GITHUB_CONTENT_TOKEN'),
        'repo' => env('GITHUB_CONTENT_REPO') ?: 'g-dias-maciel/vaif',
        'branch' => env('GITHUB_CONTENT_BRANCH') ?: 'main',
        'path' => trim((string) (env('GITHUB_CONTENT_PATH') ?: 'packages/lp/content/blog'), '/'),
        'api_url' => rtrim((string) (env('GITHUB_API_URL') ?: 'https://api.github.com'), '/'),
        'timeout' => (int) env('GITHUB_CONTENT_TIMEOUT', 30),
    ],

    'indexnow' => [
        'endpoint' => env('INDEXNOW_ENDPOINT') ?: 'https://api.indexnow.org/indexnow',
        'key' => env('INDEXNOW_KEY') ?: 'vaif-blog-indexnow-2026',
        'key_location' => (string) (env('INDEXNOW_KEY_LOCATION') ?: $lpBase.'/vaif-blog-indexnow-2026.txt'),
    ],

    'verify' => [
        'attempts' => (int) env('CONTENT_VERIFY_ATTEMPTS', 20),
        'sleep_seconds' => (int) env('CONTENT_VERIFY_SLEEP', 15),
    ],

    'catalog' => [
        // Used to avoid publishing a slug that already exists outside the CRM.
        'sitemap_url' => $lpBase.'/sitemap.xml',
        'cache_seconds' => (int) env('CONTENT_CATALOG_CACHE', 900),
    ],

    'intake' => [
        // LP endpoint that mints a single-use intake link.
        'url' => (string) env('LP_INTAKE_TOKEN_URL', $lpBase.'/api/artists/intake-token.php'),
        'key' => env('LP_INTAKE_API_KEY'),
        'timeout' => (int) env('LP_INTAKE_API_TIMEOUT', 15),
    ],

    'mail' => (bool) env('CONTENT_MAIL', true),

    // Empty = every staff user.
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CONTENT_RECIPIENTS', '')),
    ))),
];
