<?php

namespace App\Http\Controllers;

use App\Jobs\CompareDraft;
use App\Jobs\GenerateMockDrafts;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\ObservedWrite;
use App\Services\Agent\CalibrationChat;
use App\Services\Agent\CalibrationConversation;
use App\Services\Agent\CalibrationReport;
use App\Services\WahaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Staff calibration panel for copilot mode.
 *
 * Shows draft-vs-artist quality and gates, lets staff change the reply mode
 * (off / observe / draft / auto) and point a WAHA session at the CRM or back at n8n.
 */
class CalibrationController extends Controller
{
    public function index(CalibrationReport $report)
    {
        $artists = Artist::with('settings')->orderBy('name')->get();

        $rows = $artists->map(fn (Artist $artist) => [
            'artist' => $artist,
            'report' => $report->forArtist($artist, 30),
        ]);

        return view('calibration.index', [
            'rows' => $rows,
            'schemaReady' => $this->schemaReady(),
            'pendingWrites' => $this->pendingWrites(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ObservedWrite>
     */
    private function pendingWrites()
    {
        try {
            return ObservedWrite::query()
                ->with(['artist:id,name', 'lead:id,name'])
                ->where('status', ObservedWrite::STATUS_PENDING)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function show(Artist $artist, CalibrationReport $report, WahaService $waha, CalibrationChat $chat, CalibrationConversation $conversation)
    {
        $artist->loadMissing('settings');

        try {
            $drafts = AgentDraft::query()
                ->with('lead:id,name')
                ->where('artist_id', $artist->id)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();
        } catch (\Throwable $e) {
            $drafts = collect();
        }

        // Group by conversation. A busy chat produces several drafts; showing
        // 40 flat cards hides which one is the current suggestion.
        $draftGroups = $drafts
            ->groupBy(fn (AgentDraft $draft) => $draft->lead_id ?: $draft->chat_id)
            ->map(function ($group) use ($chat, $conversation) {
                $sorted = $group->sortByDesc('created_at')->values();
                $latest = $sorted->first();
                $blocks = $latest?->lead ? $conversation->forLead($latest->lead, 200) : [];
                $missing = collect($blocks)->filter(
                    fn ($block) => ! empty($block['message']['id']) && $block['draft'] === null
                )->count();

                return [
                    'latest' => $latest,
                    'count' => $sorted->count(),
                    'history' => $sorted->slice(1, 8)->values(),
                    'lead' => $latest?->lead,
                    'lead_name' => $latest?->lead?->name,
                    'chat_id' => $latest?->chat_id,
                    'turns' => $latest?->lead ? $chat->forLead($latest->lead, 80) : [],
                    'missing' => $missing,
                ];
            })
            ->sortByDesc(fn ($group) => $group['latest']?->created_at)
            ->take(20)
            ->values();

        return view('calibration.show', [
            'artist' => $artist,
            'report' => $report->forArtist($artist, 30),
            'draftGroups' => $draftGroups,
            'draftTotal' => $drafts->count(),
            'webhook' => $waha->webhookInfo($artist),
            'crmWebhookUrl' => (string) config('transport.waha_webhook_url'),
            'n8nWebhookUrl' => (string) config('transport.n8n_webhook_url'),
            'schemaReady' => $this->schemaReady(),
        ]);
    }

    public function updateMode(Request $request, Artist $artist, CalibrationReport $report)
    {
        $data = $request->validate([
            'reply_mode' => ['required', 'in:off,observe,draft,auto'],
            'force' => ['nullable', 'boolean'],
        ]);

        if (! $this->schemaReady()) {
            return back()->with('error', 'A migração de calibração ainda não foi aplicada (0033_agent_calibration.sql).');
        }

        $target = (string) $data['reply_mode'];

        if ($target === 'observe' && ! $this->observeSchemaReady()) {
            return back()->with('error', 'A migração do modo observar ainda não foi aplicada (0042_observe_mode.sql).');
        }
        $force = $request->boolean('force');

        if ($target === 'auto' && ! $force) {
            $calibration = $report->forArtist($artist, 30);

            if (! $calibration['passed']) {
                return back()->with('error', 'Os gates de calibração ainda não passaram. Revise os gates ou marque a opção de força explícita.');
            }
        }

        $artist->loadMissing('settings');
        $previous = $artist->settings?->reply_mode ?? 'auto';

        ArtistSetting::updateOrCreate(
            ['artist_id' => $artist->id],
            ['reply_mode' => $target],
        );

        Log::info('Agent reply mode changed', [
            'artist_id' => $artist->id,
            'from' => $previous,
            'to' => $target,
            'force' => $force,
            'user_id' => $request->user()?->id,
        ]);

        return back()->with('status', "Modo do agente atualizado: {$previous} → {$target}.");
    }

    public function updateWebhook(Request $request, Artist $artist, WahaService $waha)
    {
        $data = $request->validate([
            'target' => ['required', 'in:crm,n8n'],
        ]);

        if (! $artist->wa_session_slug) {
            return back()->with('error', 'Este artista não tem uma sessão WhatsApp configurada.');
        }

        $target = (string) $data['target'];
        $url = $target === 'crm'
            ? (string) config('transport.waha_webhook_url')
            : (string) config('transport.n8n_webhook_url');

        $token = $target === 'crm'
            ? (string) config('transport.waha_webhook_token')
            : (config('transport.n8n_webhook_token') ? (string) config('transport.n8n_webhook_token') : null);

        $events = $target === 'crm'
            ? ['message.any', 'presence.update']
            : ['message', 'presence.update'];

        $ok = $waha->updateWebhook($artist, $url, $token, $events);

        Log::info('Agent webhook switch attempted', [
            'artist_id' => $artist->id,
            'target' => $target,
            'url' => $url,
            'ok' => $ok,
            'user_id' => $request->user()?->id,
        ]);

        return back()->with(
            $ok ? 'status' : 'error',
            $ok
                ? 'Webhook atualizado no WAHA. A sessão continua autenticada; não precisa ler o QR.'
                : 'Não foi possível atualizar o webhook. Verifique a conexão com o WAHA e a API key.'
        );
    }

    public function mock(Request $request, Artist $artist, Lead $lead)
    {
        abort_unless($lead->artist_id === $artist->id, 404);

        if (! $this->schemaReady()) {
            return back()->with('error', 'A migração de calibração ainda não foi aplicada.');
        }

        $limit = min(20, max(1, (int) $request->input('limit', 10)));

        GenerateMockDrafts::dispatch($artist->id, $lead->id, $limit)
            ->onQueue((string) config('agent.queue', 'agent'));

        return back()->with(
            'status',
            "Gerando até {$limit} resposta(s) simuladas da Beatriz para esta conversa. Nada será enviado ao lead."
        );
    }

    /**
     * Staff describes the image/video the artist sent, so the pair can be
     * compared instead of being counted as an uncomparable media turn.
     */
    public function mediaNote(Request $request, Artist $artist, AgentDraft $draft)
    {
        abort_unless($draft->artist_id === $artist->id, 404);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $draft->forceFill([
            'artist_media_note' => trim($data['note']),
        ])->save();

        CompareDraft::dispatch((string) $draft->id, true)
            ->onQueue((string) config('agent.queue', 'agent'));

        return back()->with('status', 'Mídia descrita. A comparação está sendo refeita.');
    }

    private function schemaReady(): bool
    {
        try {
            return Schema::hasTable('agent_drafts')
                && Schema::hasColumn('artist_settings', 'reply_mode');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function observeSchemaReady(): bool
    {
        try {
            return Schema::hasTable('observed_turns')
                && Schema::hasTable('lead_summaries');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
