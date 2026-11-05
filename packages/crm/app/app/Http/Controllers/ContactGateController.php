<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\WahaService;
use Illuminate\Http\Request;

/**
 * Internal endpoint used by n8n on a lead's first contact.
 *
 * If the number is in the artist's saved WhatsApp contacts, the agent must not
 * answer on its own: the lead is marked `contact_gate = pending` and the
 * response tells the workflow to hold. The artist decides on the dashboard
 * (and via e-mail) whether the agent may answer.
 *
 * Authenticated with the shared header token (`X-Crm-Token`), like the
 * reference endpoints.
 */
class ContactGateController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = (string) config('transport.crm_internal_token');
        $provided = (string) $request->header('X-Crm-Token');

        abort_unless($expected !== '' && hash_equals($expected, $provided), 401);

        $data = $request->validate([
            'artist_id' => ['required', 'uuid'],
            'lead_id' => ['required', 'uuid'],
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:4096'],
        ]);

        $lead = Lead::where('id', $data['lead_id'])
            ->where('artist_id', $data['artist_id'])
            ->first();

        abort_unless($lead, 404);

        // The artist already silenced this lead (or a previous decision exists).
        if ($lead->agent_blocked_at !== null) {
            return response()->json(['hold' => true, 'gate' => $lead->contact_gate]);
        }

        if ($lead->contact_gate !== null) {
            return response()->json([
                'hold' => $lead->contact_gate !== 'allowed',
                'gate' => $lead->contact_gate,
            ]);
        }

        $artist = $lead->artist;
        $saved = $artist
            ? WahaService::make()->isSavedContact($artist, (string) $lead->phone_e164)
            : null;

        // Fail open: if WAHA cannot answer, let the agent handle the lead
        // rather than leaving it unanswered on an infrastructure error.
        if ($saved === true) {
            $lead->forceFill([
                'contact_gate' => 'pending',
                'contact_gate_message' => $data['message'] ?? $lead->contact_gate_message,
            ])->save();

            return response()->json(['hold' => true, 'gate' => 'pending']);
        }

        return response()->json(['hold' => false, 'gate' => null]);
    }
}
