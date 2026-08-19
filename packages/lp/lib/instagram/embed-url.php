<?php

declare(strict_types=1);

function instagram_embed_url(string $url): string
{
    if (!preg_match('#(?:instagram\.com|instagr\.am)/(?:[A-Za-z0-9_.]+/)?(p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $url, $m)) {
        return $url;
    }

    $type = $m[1] === 'reels' ? 'reel' : $m[1];

    return "https://www.instagram.com/{$type}/{$m[2]}/";
}
