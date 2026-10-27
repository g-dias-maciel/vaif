<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Resolve the Artist the current request is scoped to:
     * - an artist user → their own artist;
     * - a staff user → the artist they selected (session).
     */
    protected function activeArtist(): ?Artist
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isArtist()) {
            return $user->artist;
        }

        $id = session('staff_artist_id');

        return $id ? Artist::find($id) : null;
    }
}
