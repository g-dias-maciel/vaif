<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for server-to-server ingest endpoints (calculator and
 * Notion sync). The token is read from the config key passed to the route, so
 * one middleware can protect both feeds with independent secrets.
 */
class EnsureSalesIngestToken
{
    public function handle(Request $request, Closure $next, string $configKey = 'crm.sales_ingest_token'): Response
    {
        $expected = (string) config($configKey);

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'ingest is not configured',
            ], 503);
        }

        $token = $request->bearerToken() ?: $request->input('token');

        if (! is_string($token) || ! hash_equals($expected, $token)) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
