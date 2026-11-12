<?php

namespace App\Console\Commands;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\AgentStore;
use App\Services\Agent\MessageText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turn failed real conversations into replayable scenario files.
 *
 * The output can be run with `agent:eval --dir=<dir>` against the current
 * prompt and model, and judged against the artist's real answer. The files
 * contain customer text, so the default destination is runtime storage, not
 * the repository.
 */
class AgentExportRegression extends Command
{
    protected $signature = 'agent:export-regression
        {artist : Artist UUID or WAHA session slug}
        {--days=7 : Only drafts compared in this window}
        {--limit=10 : Maximum cases to export}
        {--ids= : Comma-separated draft UUIDs}
        {--dir= : Destination directory}
        {--force : Overwrite existing files}';

    protected $description = 'Export failed real drafts as replayable agent:eval scenarios';

    public function handle(AgentStore $store): int
    {
        $artist = $this->artist();

        if ($artist === null) {
            $this->error('Artist not found.');

            return self::FAILURE;
        }

        $dir = (string) ($this->option('dir') ?: storage_path('app/agent-regressions'));
        File::ensureDirectoryExists($dir);

        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ids')))));

        $query = AgentDraft::query()
            ->where('artist_id', $artist->id)
            ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
            ->where('status', AgentDraft::STATUS_COMPARED)
            ->whereNotNull('artist_reply')
            ->whereNotNull('comparison')
            ->where('compared_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->orderByDesc('compared_at');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where(function ($q) {
                $q->whereRaw("(comparison->'judge'->>'acceptable') = 'false'")
                    ->orWhereRaw("(comparison->'judge'->>'verdict') = 'artist_better'")
                    ->orWhereRaw("(comparison->'judge'->>'missing_handoff') = 'true'");
            });
        }

        $drafts = $query->limit(max(1, (int) $this->option('limit')))->get();
        $rows = [];
        $exported = 0;
        $skipped = 0;

        foreach ($drafts as $draft) {
            $artistReply = trim((string) ($draft->artist_media_note ?: $draft->artist_reply));

            if ($artistReply === '' || MessageText::isMediaOnly($artistReply)) {
                $skipped++;

                continue;
            }

            $file = rtrim($dir, '/').'/regression-'.substr((string) $draft->id, 0, 8).'.json';

            if (File::exists($file) && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $scenario = $this->scenario($draft, $artist, $store, $artistReply);
            File::put($file, json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $judge = $draft->comparison['judge'] ?? [];
            $rows[] = [
                substr((string) $draft->id, 0, 8),
                $draft->intent,
                (string) ($judge['verdict'] ?? '-'),
                ($judge['acceptable'] ?? false) ? 'yes' : 'no',
                ($judge['missing_handoff'] ?? false) ? 'yes' : 'no',
                Str::limit((string) ($judge['rationale'] ?? ''), 50),
            ];

            $exported++;
        }

        $this->table(['id', 'intent', 'old verdict', 'acceptable', 'missed handoff', 'rationale'], $rows);
        $this->info("Exported {$exported} case(s) to {$dir} ({$skipped} skipped).");

        if ($exported > 0) {
            $this->line("Run with: php artisan agent:eval --dir={$dir}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(AgentDraft $draft, Artist $artist, AgentStore $store, string $artistReply): array
    {
        $facts = is_array($draft->facts) ? $draft->facts : [];
        $project = is_array($facts['project'] ?? null) ? $facts['project'] : [];
        $client = is_array($facts['client'] ?? null) ? $facts['client'] : [];
        $booking = is_array($facts['booking'] ?? null) ? $facts['booking'] : [];
        $judge = is_array($draft->comparison['judge'] ?? null) ? $draft->comparison['judge'] : [];
        $missing = is_array($judge['missing'] ?? null) ? $judge['missing'] : [];

        $history = [];
        try {
            $history = $store->history(
                (string) $draft->lead_id,
                16,
                $draft->lead_msg_at?->toIso8601String(),
            );
        } catch (\Throwable $e) {
            // History is best effort; the case still runs without it.
        }

        // Old drafts did not store agent_state. The current chat session is the
        // best available reconstruction (photo confirmed, price gate, etc.).
        $state = is_array($facts['turn']['agent_state'] ?? null) ? $facts['turn']['agent_state'] : [];
        if ($state === []) {
            try {
                $row = DB::selectOne(
                    'select agent_state from chat_sessions where artist_id = ?::uuid and chat_id = ?',
                    [$artist->id, (string) $draft->chat_id]
                );
                $decoded = is_string($row->agent_state ?? null) ? json_decode($row->agent_state, true) : null;
                $state = is_array($decoded) ? $decoded : [];
            } catch (\Throwable $e) {
                $state = [];
            }
        }

        $settings = $artist->settings?->toArray() ?? [];
        unset(
            $settings['id'], $settings['artist_id'], $settings['created_at'], $settings['updated_at'],
            $settings['telegram_group_id'], $settings['whatsapp_number'], $settings['blocked_numbers'],
            $settings['ai_active_hours'],
        );
        $settings['pix_key'] = 'pix@regression.test';

        $expect = ['sent' => true];
        $judgeExpect = [
            'acceptable' => true,
            'verdict_not' => ['artist_better'],
        ];

        if (! empty($judge['missing_handoff'])) {
            $judgeExpect['missing_handoff'] = false;
            $expect['reply_contains'] = [$artist->settings?->display_name ?: $artist->name];
        }

        $repeatType = false;
        foreach ($missing as $item) {
            $text = Str::ascii(mb_strtolower((string) $item));
            if (str_contains($text, 'repet') && str_contains($text, 'tipo')) {
                $repeatType = true;
            }
        }

        if ($repeatType) {
            $expect['reply_not_contains'] = ['nova, uma cobertura ou uma reforma', 'nova, cobertura ou reforma'];
        }

        $draftRules = array_map(
            fn ($v) => is_array($v) ? (string) ($v['rule'] ?? '') : (string) $v,
            (array) ($draft->validation['original_violations'] ?? []),
        );

        if (in_array('spam_qualification', $draftRules, true)) {
            $expect['reply_not_contains'] = array_values(array_unique(array_merge(
                (array) ($expect['reply_not_contains'] ?? []),
                ['qual o seu nome', 'seu nome'],
            )));
        }

        return [
            'name' => 'regression-'.substr((string) $draft->id, 0, 8),
            'description' => 'Real case '.$draft->created_at?->toIso8601String()
                .' · old verdict '.($judge['verdict'] ?? '?')
                .' · '.(string) ($judge['rationale'] ?? ''),
            'artist' => [
                'id' => $artist->id,
                'name' => $artist->name,
                'timezone' => $artist->timezone ?: 'America/Sao_Paulo',
                'wa_session_slug' => $artist->wa_session_slug,
            ],
            'settings' => $settings,
            'phone' => '+5511999999999',
            'chat_id' => (string) $draft->chat_id,
            'message' => (string) $draft->lead_text,
            'turn' => [
                'name' => $client['name'] ?? null,
                'is_new' => false,
                'stage_key' => $facts['turn']['stage_key'] ?? 'qualificando',
                'tattoo_type' => $project['tattoo_type'] ?? null,
                'placement' => $project['placement'] ?? null,
                'body_zone' => $project['body_zone'] ?? null,
                'style' => $project['style'] ?? null,
                'first_tattoo' => $project['first_tattoo'] ?? null,
                'meaning' => $project['meaning'] ?? null,
                'agent_state' => $state,
                'deposit_status' => $booking['deposit_status'] ?? null,
                'table_price_cents' => $facts['pricing']['base_cents'] ?? null,
                'quoted_price_cents' => $facts['pricing']['offered_cents'] ?? null,
                'active_booking_start' => $booking['start_at'] ?? null,
            ],
            'facts' => $facts,
            'history' => $history,
            'artist_reply' => $artistReply,
            'expect' => $expect,
            'judge_expect' => $judgeExpect,
        ];
    }

    private function artist(): ?Artist
    {
        $key = (string) $this->argument('artist');
        $query = Artist::query()->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        return $query->first();
    }
}
