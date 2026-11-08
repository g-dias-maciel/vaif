<?php

namespace App\Policies;

use App\Models\Prospect;
use App\Models\User;

/**
 * The internal sales pipeline is visible to every sales user and to staff.
 * Reassignment and deletion stay with staff.
 */
class ProspectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSales() || $user->isStaff();
    }

    public function view(User $user, Prospect $prospect): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Prospect $prospect): bool
    {
        return $this->viewAny($user);
    }

    public function handoff(User $user, Prospect $prospect): bool
    {
        return $this->viewAny($user);
    }

    public function reassign(User $user): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Prospect $prospect): bool
    {
        return $user->isStaff();
    }
}
