<?php

namespace App\Services\Agent;

use App\Models\Artist;

interface MessageTransport
{
    public function send(Artist $artist, string $chatId, string $text): bool;

    public function typing(Artist $artist, string $chatId, bool $on): void;
}
