<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        // Every deployment runs behind Coolify's Traefik reverse proxy, which
        // terminates TLS and forwards X-Forwarded-*. If the proxy is not
        // trusted, Laravel builds http:// URLs and non-secure cookies while the
        // browser is on https://, which breaks logins and assets.
        //
        // Only the Docker/private networks are trusted: trusting "*" would let
        // any client spoof X-Forwarded-For and bypass IP rate limits.
        $configured = config('app.trusted_proxies');

        $this->proxies = is_string($configured) && $configured !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $configured))))
            : ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1'];
    }
}
