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
     * Whether the user can confirm the tattoo was finished (and the lead
     * becomes a returning customer).
     */
    public function complete(User $user, Project $project): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $project->artist_id === $user->artist_id
            && ! in_array($project->stage_key, ['fechado', 'perdido'], true);
    }

    /**
     * Whether the user can manually move this Project to `agendado`.
     */
    public function schedule(User $user, Project $project): bool
    {
        // Manual scheduling exists for triage-only artists: the agent never
        // quotes or books for them, so they move the pipeline themselves.
        if ($project->artist?->settings?->full_selling !== false) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $project->artist_id === $user->artist_id
            && ! in_array($project->stage_key, ['fechado', 'perdido'], true);
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
