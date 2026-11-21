<?php

namespace App\Support;

/**
 * Safe "return to" handling for finance drill-down pages.
 *
 * Links pass `return_to=<current URL>`; controllers and views only honour it
 * when it points at the same host and the /finance area, so it cannot be used
 * as an open redirect.
 */
class FinanceNavigation
{
    public static function backUrl(?string $returnTo, string $fallback): string
    {
        $returnTo = trim((string) $returnTo);

        if ($returnTo === '') {
            return $fallback;
        }

        $path = parse_url($returnTo, PHP_URL_PATH) ?: '/';

        if ($path !== '/finance' && ! str_starts_with($path, '/finance/')) {
            return $fallback;
        }

        if (str_starts_with($returnTo, '/')) {
            return $returnTo;
        }

        $host = parse_url($returnTo, PHP_URL_HOST);

        if ($host === null) {
            return $fallback;
        }

        $allowed = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]);

        foreach ($allowed as $allowedHost) {
            if (strcasecmp($host, (string) $allowedHost) === 0) {
                return $returnTo;
            }
        }

        return $fallback;
    }

    public static function withReturnTo(string $url, ?string $returnTo): string
    {
        $returnTo = self::backUrl($returnTo, '');

        if ($returnTo === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'return_to='.urlencode($returnTo);
    }
}
