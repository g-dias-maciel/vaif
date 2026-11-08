<?php

namespace App\Services\Agent\Eval;

use App\Models\Artist;
use App\Services\Agent\MessageTransport;

/**
 * Records what the agent would have sent, without touching WAHA.
 */
class ArrayTransport implements MessageTransport
{
    /** @var array<int, string> */
    public array $sent = [];

    /** @var array<int, bool> */
    public array $typing = [];

    public function send(Artist $artist, string $chatId, string $text): bool
    {
        $this->sent[] = $text;

        return true;
    }

    public function typing(Artist $artist, string $chatId, bool $on): void
    {
        $this->typing[] = $on;
    }
}
