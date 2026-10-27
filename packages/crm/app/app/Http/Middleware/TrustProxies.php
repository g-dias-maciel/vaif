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
        // Defaults to "*" because the app is only reachable through the proxy;
        // set TRUSTED_PROXIES to an allow-list if that ever stops being true.
        $this->proxies = config('app.trusted_proxies', '*');
    }
}
