<?php

namespace App\Policies;

use App\Models\CalendarEntry;
use App\Models\User;

class BookingPolicy
{
    /**
     * Whether the user can see this booking.
     */
    public function view(User $user, CalendarEntry $slot): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->artist_id !== null && $slot->artist_id === $user->artist_id;
    }

    /**
     * Whether the user can reschedule this booking (also used by cancel and
     * confirm-deposit).
     */
    public function reschedule(User $user, CalendarEntry $slot): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $slot->artist_id === $user->artist_id
            && $slot->kind === 'booking'
            && $slot->active;
    }

    /**
     * Whether the user can remove a blocked period.
     */
    public function unblock(User $user, CalendarEntry $slot): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $slot->artist_id === $user->artist_id
            && $slot->kind === 'block';
    }
}
