<?php

namespace App\Http\Controllers;

use App\Jobs\AuditConversation;
use App\Models\Lead;
use Illuminate\Http\Request;

/**
 * Staff-only on-demand conversation audit.
 */
class ConversationAuditController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        AuditConversation::dispatch($lead->artist_id, $lead->id, true)
            ->onQueue((string) config('agent.queue', 'agent'));

        return back()->with('status', 'Auditoria enfileirada. Atualize a página em alguns segundos.');
    }
}
