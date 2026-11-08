<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Services\WahaService;

class WahaTransport implements MessageTransport
{
    public function __construct(private readonly WahaService $waha)
    {
    }

    public function send(Artist $artist, string $chatId, string $text): bool
    {
        return $this->waha->sendText($artist, $chatId, $text);
    }

    public function typing(Artist $artist, string $chatId, bool $on): void
    {
        $on ? $this->waha->startTyping($artist, $chatId) : $this->waha->stopTyping($artist, $chatId);
    }
}
