<?php

namespace App\Policies;

use App\Models\CalendarSlot;
use App\Models\User;

class BookingPolicy
{
    /**
     * Whether the user can see this booking.
     */
    public function view(User $user, CalendarSlot $slot): bool
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
    public function reschedule(User $user, CalendarSlot $slot): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $slot->artist_id === $user->artist_id
            && $slot->type === 'booked';
    }

    /**
     * Whether the user can remove a blocked period.
     */
    public function unblock(User $user, CalendarSlot $slot): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isArtist()
            && $user->artist_id !== null
            && $slot->artist_id === $user->artist_id
            && $slot->type === 'blocked';
    }
}
