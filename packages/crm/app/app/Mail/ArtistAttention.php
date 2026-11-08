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
     * @param  array<int, array{name: string, phone: ?string, url: string}>  $contacts
     */
    /**
     * @param  string  $stage  first | reminder | escalation
     */
    public function __construct(
        public Artist $artist,
        public array $handoffs = [],
        public array $deposits = [],
        public array $contacts = [],
        public string $stage = 'first',
    ) {
    }

    public function build(): self
    {
        $count = count($this->handoffs) + count($this->deposits) + count($this->contacts);

        $subject = match ($this->stage) {
            'escalation' => '[URGENTE] '.$count.' cliente'.($count === 1 ? '' : 's').' sem resposta sua',
            'reminder' => 'Lembrete: '.$count.' cliente'.($count === 1 ? '' : 's').' ainda aguardando você',
            default => $count.' cliente'.($count === 1 ? '' : 's').' aguardando você',
        };

        return $this
            ->subject($subject)
            ->view('emails.artist-attention', ['stage' => $this->stage]);
    }
}
