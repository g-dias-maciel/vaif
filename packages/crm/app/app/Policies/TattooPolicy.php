<?php

namespace App\Policies;

use App\Models\Tattoo;
use App\Models\User;

class TattooPolicy
{
    /**
     * Whether the user can see this Tattoo (project).
     */
    public function view(User $user, Tattoo $tattoo): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->artist_id !== null && $tattoo->artist_id === $user->artist_id;
    }

    /**
     * Whether the user can mark this Tattoo lost.
     */
    public function markLost(User $user, Tattoo $tattoo): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $tattoo->artist_id === $user->artist_id;
    }
}
