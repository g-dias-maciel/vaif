<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ArtistInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {
    }

    public function build(): self
    {
        $url = route('password.set', [
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return $this->subject('Defina sua senha — VAIF CRM')
            ->view('emails.artist-invite', [
                'user' => $this->user,
                'url' => $url,
            ]);
    }
}
