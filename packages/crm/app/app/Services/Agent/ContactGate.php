<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Lead;
use App\Services\WahaService;

/**
 * Saved-contact gate: a number already in the artist's WhatsApp contacts is
 * not answered by the agent on first contact until the artist allows it.
 *
 * Mirrors `App\Http\Controllers\ContactGateController`, which remains for the
 * n8n fallback, but runs in-process for the Laravel agent.
 */
class ContactGate
{
    public function __construct(private readonly WahaService $waha)
    {
    }

    public function shouldHold(Artist $artist, array $turn): bool
    {
        $lead = Lead::where('id', $turn['lead_id'] ?? null)
            ->where('artist_id', $artist->id)
            ->first();

        if (! $lead) {
            return false;
        }

        if ($lead->agent_blocked_at !== null) {
            return true;
        }

        if ($lead->contact_gate !== null) {
            return $lead->contact_gate !== 'allowed';
        }

        $saved = $this->waha->isSavedContact($artist, (string) $lead->phone_e164);

        if ($saved === true) {
            $lead->forceFill(['contact_gate' => 'pending'])->save();

            return true;
        }

        return false;
    }
}
