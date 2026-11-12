<?php

namespace App\Http\Controllers;

use App\Models\AgentDraft;
use App\Models\ChatMessage;
use App\Models\ConversationAudit;
use App\Models\Lead;
use App\Models\LeadSummary;
use App\Models\Note;
use App\Models\ObservedWrite;
use App\Models\Project;
use App\Services\Agent\ConversationTimeline;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    /**
     * Detail view of a Lead: contact data plus every Project (tattoo job)
     * attached to this person, with its quotes, bookings and events.
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $lead->load([
            'notes.author',
            'projects.quotes.promotion',
            'projects.acceptedQuote',
            'projects.payments',
            'projects.calendar',
            'events',
        ]);

        $artist = $this->activeArtist();

        return view('leads.show', [
            'lead' => $lead,
            'pipeline' => config('crm.pipeline_statuses'),
            'timeline' => app(ConversationTimeline::class)->forLead($lead),
            'eventLabels' => config('crm.event_labels'),
            'fullSelling' => (bool) ($artist?->settings?->full_selling ?? true),
            'chatOwner' => $this->chatOwner($lead),
            'summary' => $this->summaryFor($lead),
            'pendingWrites' => $this->pendingWritesFor($lead),
            'audit' => auth()->user()?->isStaff() ? $this->auditFor($lead) : null,
        ]);
    }

    /**
     * Latest staff-only methodology audit for this Lead.
     */
    private function auditFor(Lead $lead): ?ConversationAudit
    {
        try {
            return ConversationAudit::query()
                ->where('lead_id', $lead->id)
                ->orderByDesc('generated_at')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Observe mode's review queue for this Lead.
     *
     * @return \Illuminate\Support\Collection<int, ObservedWrite>
     */
    private function pendingWritesFor(Lead $lead)
    {
        try {
            return ObservedWrite::query()
                ->where('lead_id', $lead->id)
                ->where('status', ObservedWrite::STATUS_PENDING)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Observe mode's rolling conversation summary, when one has been generated.
     */
    private function summaryFor(Lead $lead): ?LeadSummary
    {
        try {
            return LeadSummary::find($lead->id);
        } catch (\Throwable $e) {
            // Before the observe migration (or on SQLite dev), there is none.
            return null;
        }
    }

    /**
     * The conversation panel, polled by the lead page so new messages appear
     * without a full reload (and without losing a note being typed).
     */
    public function conversation(Lead $lead)
    {
        $this->authorize('view', $lead);

        return view('leads._conversation', [
            'lead' => $lead,
            'timeline' => app(ConversationTimeline::class)->forLead($lead),
            'chatOwner' => $this->chatOwner($lead),
        ]);
    }

    /**
     * `agent` or `artist` — who owns the WhatsApp chat right now.
     */
    private function chatOwner(Lead $lead): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'agent';
        }

        try {
            $owner = DB::table('chat_sessions')
                ->where('artist_id', $lead->artist_id)
                ->where('lead_id', $lead->id)
                ->orderByDesc('updated_at')
                ->value('owner');

            return $owner === 'artist' ? 'artist' : 'agent';
        } catch (\Throwable $e) {
            return 'agent';
        }
    }

    /**
     * One active copilot suggestion plus a collapsed history.
     *
     * A busy chat can generate several drafts; showing all of them at once
     * makes the conversation unreadable.
     *
     * @return array{latest: ?AgentDraft, history: \Illuminate\Support\Collection<int, AgentDraft>, count: int}
     */
    private function latestDrafts(Lead $lead): array
    {
        try {
            $drafts = AgentDraft::query()
                ->where('lead_id', $lead->id)
                ->orderByDesc('created_at')
                ->limit(40)
                ->get();
        } catch (\Throwable $e) {
            return ['latest' => null, 'history' => collect(), 'count' => 0];
        }

        $latest = $drafts->first(fn (AgentDraft $draft) => $draft->isOpen()) ?: $drafts->first();
        $history = $latest
            ? $drafts->where('id', '!=', $latest->id)->take(10)->values()
            : collect();

        return [
            'latest' => $latest,
            'history' => $history,
            'count' => $drafts->count(),
        ];
    }

    private function latestChatMessages(Lead $lead)
    {
        // `agent_memory` is Postgres-only (n8n's memory schema); SQLite local
        // dev/tests have no conversation transcript.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect();
        }

        // n8n's LangChain memory stores tool envelopes alongside the real
        // messages. They are internal JSON (availability rows, booking rows,
        // etc.) and must never appear in the artist-facing transcript.
        return $lead->chatMessages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get()
            ->filter(fn (ChatMessage $message) => $message->is_displayable)
            ->reverse()
            ->values()
            ->take(-50)
            ->values();
    }

    /**
     * Silence or reactivate the SDR for this Lead. The agent still records the
     * inbound message but sends no automatic reply while the flag is set.
     */
    public function toggleAgent(Lead $lead)
    {
        $this->authorize('flagAgent', $lead);

        $blocking = $lead->agent_blocked_at === null;

        $lead->forceFill([
            'agent_blocked_at' => $blocking ? now() : null,
        ])->save();

        $this->syncBlocklist($lead, $blocking);

        return back()->with('status', $blocking
            ? 'Agente silenciado para este cliente. A Beatriz não responderá mais automaticamente.'
            : 'Agente reativado para este cliente.');
    }

    /**
     * Saved-contact gate: the agent held an incoming message from a number in
     * the artist's contacts. The artist decides whether the agent may answer.
     */
    public function decideContactGate(Request $request, Lead $lead)
    {
        $this->authorize('flagAgent', $lead);

        $decision = $request->validate([
            'decision' => ['required', 'in:allow,block'],
        ])['decision'];

        if ($decision === 'allow') {
            $lead->forceFill([
                'contact_gate' => 'allowed',
                'contact_gate_notified_at' => null,
            ])->save();
            $this->syncBlocklist($lead, false);
            // The held first message was never answered (the flow stops before
            // the AI). Replay it so Beatriz greets the contact right away.
            $this->replayHeldMessage($lead);

            return back()->with('status', 'Contato liberado. A Beatriz já vai responder.');
        }

        $lead->forceFill([
            'contact_gate' => 'blocked',
            'contact_gate_notified_at' => null,
            'agent_blocked_at' => $lead->agent_blocked_at ?? now(),
        ])->save();
        $this->syncBlocklist($lead, true);

        return back()->with('status', 'Contato bloqueado. A Beatriz não responderá mais.');
    }

    /**
     * Replay the message the saved-contact gate held, so the agent answers it
     * now that the artist allowed the contact.
     */
    private function replayHeldMessage(Lead $lead): void
    {
        $text = trim((string) $lead->contact_gate_message);

        if ($text === '') {
            return;
        }

        $session = (string) ($lead->artist?->wa_session_slug ?? '');

        if ($session === '') {
            return;
        }

        // `chat_sessions` is Postgres-only (n8n owns it). When available, reuse
        // the exact chat id the gate saw (LID or @c.us) so the replay lands on
        // this Lead's conversation instead of opening a new one.
        $storedChatId = '';
        if (DB::connection()->getDriverName() === 'pgsql') {
            $storedChatId = (string) DB::table('chat_sessions')
                ->where('lead_id', $lead->id)
                ->where('artist_id', $lead->artist_id)
                ->value('chat_id');
        }

        $phone = preg_replace('/\D+/', '', (string) $lead->phone_e164);
        $from = ($storedChatId !== '' && str_contains($storedChatId, ':'))
            ? explode(':', $storedChatId, 2)[1]
            : $phone.'@c.us';

        $payload = [
            'event' => 'message',
            'session' => $session,
            'payload' => [
                'from' => $from,
                'fromMe' => false,
                'body' => $text,
                'type' => 'chat',
                'hasMedia' => false,
                'media' => null,
                'replay' => true,
                '_data' => [
                    'key' => [
                        'remoteJid' => $from,
                        'remoteJidAlt' => $phone.'@s.whatsapp.net',
                        'fromMe' => false,
                        'id' => 'replay-'.(string) Str::uuid(),
                    ],
                    'pushName' => (string) $lead->name,
                    'message' => ['conversation' => $text],
                    'replay' => true,
                ],
            ],
        ];

        $url = (string) config('transport.waha_webhook_url');
        $token = (string) config('transport.waha_webhook_token');

        if ($url === '') {
            return;
        }

        try {
            Http::withHeaders($token !== '' ? ['X-VAIF-Webhook-Token' => $token] : [])
                ->timeout(5)
                ->post($url, $payload);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $lead->forceFill(['contact_gate_message' => null])->save();
    }

    /**
     * Keep the per-artist blocklist in sync with a Lead's silenced state.
     *
     * The per-lead flag lives on a single row; if that Lead is ever re-created
     * (phone re-normalization, restore, anonymization) the silence would be
     * lost and the agent would answer again. The number-level blocklist makes
     * it durable: the "Blocked Number?" gate runs before the Lead exists.
     */
    private function syncBlocklist(Lead $lead, bool $block): void
    {
        $settings = $lead->artist->settings()->firstOrCreate(['artist_id' => $lead->artist_id]);
        $digits = preg_replace('/\D+/', '', (string) $lead->phone_e164);
        $digitsOf = fn (string $n): string => preg_replace('/\D+/', '', $n);
        $numbers = array_values(array_filter(
            array_map(fn ($n) => (string) $n, (array) ($settings->blocked_numbers ?? [])),
            fn (string $n) => $n !== '',
        ));

        if ($block) {
            if ($digits !== '' && ! in_array($digits, array_map($digitsOf, $numbers), true)) {
                $numbers[] = $digits;
            }
        } else {
            $numbers = array_values(array_filter(
                $numbers,
                fn (string $n) => $digitsOf($n) !== $digits,
            ));
        }

        $settings->forceFill(['blocked_numbers' => $numbers])->save();
    }

    /**
     * Add an internal note to a Lead.
     */
    public function storeNote(Request $request, Lead $lead)
    {
        $this->authorize('addNote', $lead);

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        Note::create([
            'lead_id' => $lead->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return back()->with('status', 'Nota adicionada.');
    }

    /**
     * Confirm the project's requested deposit from the platform. The agent
     * asks for the signal; the artist confirms it here (lead page or the
     * dashboard alert) after checking the PIX.
     */
    public function confirmDeposit(Project $project, DepositService $deposits)
    {
        $this->authorize('view', $project->lead);

        try {
            $notified = $deposits->confirm(
                $project,
                auth()->user()->isStaff() ? 'staff' : 'artist',
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Não foi possível confirmar o sinal: nenhum sinal solicitado.');
        }

        return back()->with($notified ? 'status' : 'error', $notified
            ? 'Sinal confirmado. O agendamento está liberado.'
            : 'Sinal confirmado, mas não foi possível avisar o cliente pelo WhatsApp.');
    }

    /**
     * Confirm the tattoo was finished. This is what marks the lead as a
     * returning customer; confirming the sinal alone no longer does.
     */
    public function completeProject(Project $project)
    {
        $this->authorize('complete', $project);

        try {
            DB::select('select * from complete_project(?, ?, ?)', [
                $project->artist_id,
                $project->id,
                auth()->user()->isStaff() ? 'staff' : 'artist',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Não foi possível concluir: projeto perdido ou inexistente.');
        }

        return back()->with('status', 'Tattoo concluída. Cliente marcado como recorrente.');
    }

    /**
     * Manually move a Project to `agendado`.
     *
     * Triage-only artists schedule outside the CRM (the agent never books a
     * slot), so they need a way to advance the pipeline themselves.
     */
    public function scheduleProject(Project $project)
    {
        $this->authorize('schedule', $project);

        if (! in_array($project->stage_key, ['agendado', 'fechado', 'perdido'], true)) {
            DB::select('select * from set_project_stage(?, ?, ?, ?)', [
                $project->artist_id,
                $project->id,
                'agendado',
                auth()->user()->isStaff() ? 'staff' : 'artist',
            ]);
        }

        return back()->with('status', 'Lead marcado como agendado.');
    }

    /**
     * The Artist takes over a handoff: close the pending handoff, move the
     * project back to a live stage, and silence Beatriz on this chat.
     */
    public function acceptHandoff(Project $project)
    {
        $this->authorize('resolveHandoff', $project);

        $actor = auth()->user()->isStaff() ? 'staff' : 'artist';

        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::select('select * from resolve_project_handoff(?, ?, ?)', [
                    $project->artist_id,
                    $project->id,
                    $actor,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                return back()->with('error', 'Não foi possível assumir este atendimento.');
            }
        }

        $this->takeChatControl($project->lead);

        return back()->with('status', 'Atendimento assumido. A Beatriz está silenciada nesta conversa.');
    }

    /**
     * The Artist gives the conversation back to Beatriz. A pending handoff is
     * resolved so it leaves the dashboard list.
     */
    public function returnHandoff(Project $project)
    {
        $this->authorize('resolveHandoff', $project);

        $this->returnChatControl($project->lead);
        $this->resolveOpenHandoff($project->lead, auth()->user()->isStaff() ? 'staff' : 'artist');

        return back()->with('status', 'Conversa devolvida para a Beatriz.');
    }

    /**
     * "Devolver para a Beatriz" from the conversation panel: give the chat
     * back to the agent, and close an open handoff if one exists.
     */
    public function returnChat(Lead $lead)
    {
        $this->authorize('flagAgent', $lead);

        $this->returnChatControl($lead);
        $this->resolveOpenHandoff($lead, auth()->user()->isStaff() ? 'staff' : 'artist');

        return back()->with('status', 'Conversa devolvida para a Beatriz.');
    }

    /**
     * The chat id the WhatsApp session uses for this Lead. `chat_sessions` is
     * Postgres-only; fall back to the session:phone convention.
     */
    private function chatIdFor(Lead $lead): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $stored = (string) DB::table('chat_sessions')
                ->where('artist_id', $lead->artist_id)
                ->where('lead_id', $lead->id)
                ->orderByDesc('updated_at')
                ->value('chat_id');

            if ($stored !== '') {
                return $stored;
            }
        }

        $session = (string) ($lead->artist?->wa_session_slug ?? '');
        $phone = preg_replace('/\D+/', '', (string) $lead->phone_e164);

        return $session !== '' ? $session.':'.$phone.'@c.us' : '';
    }

    private function takeChatControl(?Lead $lead): void
    {
        if ($lead === null || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $chatId = $this->chatIdFor($lead);

        if ($chatId === '') {
            return;
        }

        try {
            DB::select('select * from take_chat_control(?, ?, ?)', [
                $lead->artist_id,
                $chatId,
                $lead->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function returnChatControl(?Lead $lead): void
    {
        if ($lead === null || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $chatId = $this->chatIdFor($lead);

        if ($chatId === '') {
            return;
        }

        try {
            DB::select('select * from return_chat_control(?, ?)', [
                $lead->artist_id,
                $chatId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Resolve whichever of the Lead's projects is waiting for the artist.
     */
    private function resolveOpenHandoff(?Lead $lead, string $actor): void
    {
        if ($lead === null || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $projectId = DB::table('projects')
            ->where('artist_id', $lead->artist_id)
            ->where('lead_id', $lead->id)
            ->where('stage_key', 'aguardando_artista')
            ->whereNull('handoff_resolved_at')
            ->orderByDesc('updated_at')
            ->value('id');

        if ($projectId === null) {
            return;
        }

        try {
            DB::select('select * from resolve_project_handoff(?, ?, ?)', [
                $lead->artist_id,
                $projectId,
                $actor,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Mark one of the Lead's Projects as lost (perdido).
     */
    public function markLost(Request $request, Project $project)
    {
        $this->authorize('markLost', $project);

        if ($project->stage_key !== 'perdido') {
            DB::select('select * from set_project_stage(?, ?, ?, ?)', [
                $project->artist_id,
                $project->id,
                'perdido',
                'artist',
            ]);
        }

        return back()->with('status', 'Projeto marcado como perdido.');
    }
}
