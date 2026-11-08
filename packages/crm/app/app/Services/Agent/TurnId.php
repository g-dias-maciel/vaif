<?php

namespace App\Services\Agent;

/**
 * Stable idempotency key for one turn.
 *
 * The same chat + text inside a ten-minute bucket produces the same UUID, so a
 * retried job cannot apply twice or create a second draft.
 */
class TurnId
{
    public static function make(string $chatId, string $leadText): string
    {
        $bucket = (int) floor(time() / 600);
        $hash = hash('sha256', $chatId.'|'.$leadText.'|'.$bucket);

        return substr($hash, 0, 8).'-'
            .substr($hash, 8, 4).'-4'
            .substr($hash, 13, 3).'-8'
            .substr($hash, 17, 3).'-'
            .substr($hash, 20, 12);
    }
}
