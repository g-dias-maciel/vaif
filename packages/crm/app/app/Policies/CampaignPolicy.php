<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSales() || $user->isStaff();
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->isStaff();
    }
}
