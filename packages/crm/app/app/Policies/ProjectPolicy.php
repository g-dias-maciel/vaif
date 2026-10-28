<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Whether the user can see this Project.
     */
    public function view(User $user, Project $project): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->artist_id !== null && $project->artist_id === $user->artist_id;
    }

    /**
     * Whether the user can mark this Project as lost (perdido).
     */
    public function markLost(User $user, Project $project): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $project->artist_id === $user->artist_id;
    }
}
