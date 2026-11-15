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

        return self::uuid($chatId.'|'.$leadText.'|'.$bucket);
    }

    /**
     * Stable id for retrospective simulated drafts: the same customer message
     * must update the same row, not create a new one every ten minutes.
     */
    public static function stable(string $chatId, string $leadText): string
    {
        return self::uuid($chatId.'|'.$leadText);
    }

    private static function uuid(string $value): string
    {
        $hash = hash('sha256', $value);

        return substr($hash, 0, 8).'-'
            .substr($hash, 8, 4).'-4'
            .substr($hash, 13, 3).'-8'
            .substr($hash, 17, 3).'-'
            .substr($hash, 20, 12);
    }
}
