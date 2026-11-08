<?php

namespace App\Services\Agent;

use App\Models\Artist;

interface HandoffNotifier
{
    public function notify(Artist $artist, array $lead, array $facts, array $apply): void;
}
