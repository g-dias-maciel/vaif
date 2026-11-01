<?php

namespace App\Mail;

use App\Models\Artist;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a lead is waiting for the artist: a handoff or a requested sinal
 * that needs the PIX to be checked and confirmed.
 */
class ArtistAttention extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{name: string, phone: ?string, reason: string, url: string}>  $handoffs
     * @param  array<int, array{name: string, amount: string, url: string}>  $deposits
     */
    public function __construct(
        public Artist $artist,
        public array $handoffs = [],
        public array $deposits = [],
    ) {
    }

    public function build(): self
    {
        $count = count($this->handoffs) + count($this->deposits);

        return $this
            ->subject($count.' cliente'.($count === 1 ? '' : 's').' aguardando você')
            ->view('emails.artist-attention');
    }
}
