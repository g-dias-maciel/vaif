<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Project;
use App\Services\Scoring\LeadScoreService;
use App\Services\Scoring\RemarketingMessage;
use App\Services\Scoring\RemarketingQueue;
use App\Services\Scoring\RemarketingTouchLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Artist's remarketing work queue: Leads ranked by chance to close, with
 * a separate revival list for lost Projects.
 */
class RemarketingController extends Controller
{
    private const SEGMENTS = ['retomar', 'abertos', 'reviver'];

    private const TIERS = ['hot', 'warm', 'cold'];

    public function __construct(
        private readonly RemarketingQueue $queue,
        private readonly LeadScoreService $scores,
        private readonly RemarketingTouchLogger $touches,
        private readonly RemarketingMessage $messages,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $config = (array) config('crm.lead_score.remarketing', []);
        $allowedDays = array_map('intval', (array) ($config['allowed_days'] ?? [7, 15, 30, 60, 90]));

        $segment = (string) $request->query('segment', 'retomar');
        if (! in_array($segment, self::SEGMENTS, true)) {
            $segment = 'retomar';
        }

        $tier = $request->query('tier');
        if (! in_array($tier, self::TIERS, true)) {
            $tier = null;
        }

        $days = (int) $request->query('days', (int) ($config['default_days'] ?? 7));
        if (! in_array($days, $allowedDays, true)) {
            $days = (int) ($config['default_days'] ?? 7);
        }

        $filters = [
            'segment' => $segment,
            'tier' => $tier,
            'days' => $days,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 120),
            'page' => max(1, (int) $request->query('page', 1)),
        ];

        $result = $this->queue->forArtist($artist, $filters);

        return view('remarketing.index', [
            'artist' => $artist,
            'result' => $result,
            'filters' => $filters,
            'pipeline' => config('crm.pipeline_statuses'),
            'tierLabels' => config('crm.lead_score.tier_labels'),
            'allowedDays' => $allowedDays,
        ]);
    }

    /**
     * Record a manual touch, or redirect to WhatsApp with a prefilled message.
     */
    public function contact(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('view', $lead);

        $data = $request->validate([
            'action' => ['required', 'in:whatsapp,contacted,dismissed'],
            'segment' => ['required', 'in:retomar,abertos,reviver'],
            'project_id' => ['nullable', 'uuid'],
        ]);

        $project = null;

        if (! empty($data['project_id'])) {
            $project = Project::query()
                ->where('artist_id', $lead->artist_id)
                ->where('lead_id', $lead->id)
                ->find($data['project_id']);

            abort_if($project === null, 404);
        }

        $action = match ($data['action']) {
            'whatsapp' => RemarketingTouchLogger::ACTION_WHATSAPP,
            'contacted' => RemarketingTouchLogger::ACTION_CONTACTED,
            default => RemarketingTouchLogger::ACTION_DISMISSED,
        };

        $score = $this->scores->forLead($lead);

        $this->touches->log(
            $lead->artist,
            $lead,
            $project,
            $action,
            $data['segment'],
            $score,
            $request->user(),
        );

        if ($data['action'] === 'whatsapp') {
            if ($lead->whatsapp_url === null) {
                return back()->with('error', 'Este cliente não tem um telefone válido para o WhatsApp.');
            }

            $text = $this->messages->for($lead->artist, $lead, $project, $data['segment']);

            return redirect()->away($lead->whatsapp_url.'?text='.rawurlencode($text));
        }

        return back()->with('status', $data['action'] === 'dismissed'
            ? 'Lead dispensado. Ele sai da fila por 90 dias.'
            : 'Contato registrado. Ele sai da fila por 14 dias.');
    }
}
