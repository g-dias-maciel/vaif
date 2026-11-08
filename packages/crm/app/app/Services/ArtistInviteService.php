<?php

namespace App\Services;

use App\Mail\ArtistInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Creates the first-password token and sends the Artist invite e-mail.
 *
 * Invite delivery is best-effort: the Artist and CRM login are created even if
 * the mail transport is down; staff can resend from the Artists page.
 */
class ArtistInviteService
{
    public function send(User $user): bool
    {
        try {
            $token = Password::broker()->createToken($user);
            Mail::to($user->email)->send(new ArtistInvite($user, $token));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
