<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureArtistContext
{
    /**
     * Staff users must have selected an Artist before reaching the artist tabs.
     * Artist users always pass (they have their own artist_id).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isStaff() && ! session('staff_artist_id')) {
            return redirect()->route('artists.index');
        }

        return $next($request);
    }
}
