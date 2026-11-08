<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for the calculator → internal CRM ingest endpoint.
 */
class EnsureSalesIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('crm.sales_ingest_token');

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'sales ingest is not configured',
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
