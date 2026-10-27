<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    /**
     * Whether the user can see this Lead (the person).
     */
    public function view(User $user, Lead $lead): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->artist_id !== null && $lead->artist_id === $user->artist_id;
    }

    /**
     * Whether the user can add a note to this Lead.
     */
    public function addNote(User $user, Lead $lead): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $lead->artist_id === $user->artist_id;
    }
}
