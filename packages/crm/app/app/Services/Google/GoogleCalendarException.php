<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * A Google Calendar API failure. The status is kept so callers can distinguish
 * a deleted event (404/410 → recreate it) from a real failure.
 */
class GoogleCalendarException extends RuntimeException
{
    public function __construct(string $message, private int $status = 0)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isNotFound(): bool
    {
        return in_array($this->status, [404, 410], true);
    }
}
