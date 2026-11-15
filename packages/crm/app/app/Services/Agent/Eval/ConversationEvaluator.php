<?php

namespace App\Services\Agent\Eval;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\PriceBook;
use App\Services\Agent\AiClient;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\DatabaseAgentStore;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyComposer;
use App\Services\Agent\ReplyValidator;
use App\Services\Agent\TurnProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs a fake multi-turn conversation through the production pipeline against
 * the real Postgres RPCs and the real Beatriz model.
 *
 * Every scenario runs inside a transaction that is always rolled back, so the
 * production CRM never keeps the synthetic artist, leads or messages.
 */
class ConversationEvaluator
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(ConversationScenario $scenario): array
    {
        config(['agent.humanize' => false]);

        $startedAt = microtime(true);
        $turns = [];
        $failures = [];
        $phone = $scenario->phone();
        $chatId = $scenario->chatId($phone);

        DB::beginTransaction();

        try {
            $artist = $this->seedArtist($scenario);
            $transport = new ArrayTransport();
            $media = new ScriptedAgentMedia();

            $prompts = new PromptBuilder();
            $processor = new TurnProcessor(
                new DatabaseAgentStore(),
                new ReplyComposer(
                    $this->ai,
                    $prompts,
                    new ClaimExtractor($this->ai, $this->model),
                    new ReplyValidator(),
                ),
                $prompts,
                new PreflightClassifier($this->ai, $this->model),
                $transport,
                null,
                $media,
            );

            foreach ($scenario->turns() as $index => $turn) {
                $message = (string) ($turn['message'] ?? '');

                if (trim($message) === '') {
                    $failures[] = 'turn '.($index + 1).': empty message';

                    continue;
                }

                $transport->sent = [];
                $transport->typing = [];
                $media->next = is_array($turn['media'] ?? null) ? $turn['media'] : null;

                $turnStartedAt = microtime(true);
                $result = $processor->process(
                    $artist,
                    $chatId,
                    $phone,
                    $message,
                    $scenario->leadName(),
                    true,
                );

                $report = $this->reportTurn($index + 1, $message, $result, $transport, $turnStartedAt);
                $failures = array_merge(
                    $failures,
                    $this->checkTurn($index + 1, is_array($turn['expect'] ?? null) ? $turn['expect'] : [], $report),
                );
                $turns[] = $report;
            }

            $state = $this->finalState($artist->id, $chatId);

            return [
                'scenario' => $scenario->name(),
                'description' => $scenario->description(),
                'pass' => $failures === [],
                'turns' => $turns,
                'final' => $state,
                'failures' => $failures,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'scenario' => $scenario->name(),
                'description' => $scenario->description(),
                'pass' => false,
                'turns' => $turns,
                'failures' => array_merge($failures, ['exception: '.$e->getMessage()]),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } finally {
            DB::rollBack();
        }
    }

    private function seedArtist(ConversationScenario $scenario): Artist
    {
        $artist = Artist::create([
            'id' => (string) Str::uuid(),
            'name' => 'Adriano',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'e2e-'.Str::lower(Str::random(12)),
        ]);

        $settings = array_merge([
            'display_name' => 'Adriano',
            'specialties' => ['fine line', 'realismo', 'floral'],
            'excluded_styles' => [],
            'full_selling' => true,
            'floor_pct' => 80,
            'deposit_type' => 'percent',
            'deposit_value' => 30,
            'pix_key' => 'pix@e2e.test',
            'instagram_handle' => '@e2e.studio',
            'portfolio_url' => 'https://instagram.com/e2e.studio',
            'whatsapp_number' => '5511999999999',
            'address' => 'Rua E2E, 1',
            'city' => 'São Paulo',
            'working_hours' => (object) [],
            'ai_active_hours' => (object) [],
            'pricing_extras' => (object) [],
            'reschedule_policy_hours' => 48,
            'cancellation_policy_hours' => 48,
            'allow_hold_slot' => false,
            'guarantee_price' => false,
        ], $scenario->settings());

        ArtistSetting::create(['artist_id' => $artist->id] + $settings);

        // `payment_methods` is a Postgres text[] but the model casts it as
        // JSON, so write the array explicitly for this synthetic artist.
        DB::statement(
            "update artist_settings set payment_methods = array['pix','credit_card','cash'] where artist_id = ?",
            [$artist->id],
        );

        foreach ($scenario->priceBook() as $row) {
            PriceBook::create(array_merge([
                'artist_id' => $artist->id,
                'placement' => 'braco',
                'body_zone' => 'pequeno',
                'base_price_cents' => 35000,
                'session_duration_min' => 60,
                'buffer_min' => 30,
                'effective_from' => now(),
            ], $row));
        }

        $artist->load('settings');

        return $artist;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function reportTurn(int $number, string $message, array $result, ArrayTransport $transport, float $startedAt): array
    {
        $validation = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $preflight = is_array($result['preflight'] ?? null) ? $result['preflight'] : [];

        return [
            'turn' => $number,
            'message' => $message,
            'skipped' => $result['skipped'] ?? null,
            'intent' => $preflight['intent'] ?? null,
            'flags' => is_array($preflight['flags'] ?? null) ? $preflight['flags'] : [],
            'resolution' => $validation['resolution'] ?? ($result['skipped'] ?? null),
            'reply' => (string) ($result['reply'] ?? ($validation['final_reply'] ?? '')),
            'ok' => $validation['ok'] ?? null,
            'violations' => $this->rules($validation['violations'] ?? []),
            'original_violations' => $this->rules($validation['original_violations'] ?? []),
            'repaired' => (bool) ($result['repaired'] ?? false),
            'sent' => $transport->sent !== [],
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $expect
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function checkTurn(int $number, array $expect, array $report): array
    {
        $failures = [];
        $reply = $this->norm((string) ($report['reply'] ?? ''));
        $violations = (array) $report['violations'];

        foreach ([
            'intent' => 'intent',
            'resolution' => 'resolution',
            'skipped' => 'skipped',
        ] as $key => $field) {
            if (array_key_exists($key, $expect) && (string) $expect[$key] !== (string) ($report[$field] ?? '')) {
                $failures[] = "turn {$number}: expected {$key} ".$expect[$key].', got '.($report[$field] ?? '-');
            }
        }

        if (isset($expect['skipped_in']) && ! in_array((string) ($report['skipped'] ?? ''), array_map('strval', (array) $expect['skipped_in']), true)) {
            $failures[] = "turn {$number}: expected skipped in ".implode(', ', (array) $expect['skipped_in']).', got '.($report['skipped'] ?? '-');
        }

        foreach ((array) ($expect['flags'] ?? []) as $flag => $value) {
            if ((bool) ($report['flags'][$flag] ?? false) !== (bool) $value) {
                $failures[] = "turn {$number}: expected flag {$flag}=".($value ? 'true' : 'false');
            }
        }

        if (array_key_exists('sent', $expect) && (bool) $expect['sent'] !== (bool) $report['sent']) {
            $failures[] = "turn {$number}: expected sent=".($expect['sent'] ? 'true' : 'false');
        }

        if (array_key_exists('ok', $expect) && (bool) $expect['ok'] !== (bool) $report['ok']) {
            $failures[] = "turn {$number}: expected ok=".($expect['ok'] ? 'true' : 'false');
        }

        foreach ((array) ($expect['reply_contains'] ?? []) as $needle) {
            if (! str_contains($reply, $this->norm((string) $needle))) {
                $failures[] = "turn {$number}: reply must contain '{$needle}'";
            }
        }

        foreach ((array) ($expect['reply_not_contains'] ?? []) as $needle) {
            if (str_contains($reply, $this->norm((string) $needle))) {
                $failures[] = "turn {$number}: reply must not contain '{$needle}'";
            }
        }

        foreach ((array) ($expect['requires'] ?? []) as $requirement) {
            $error = $this->requirement((string) $requirement, $reply, $report, $violations);

            if ($error !== null) {
                $failures[] = "turn {$number}: {$error}";
            }
        }

        if (isset($expect['max_latency_ms']) && $report['latency_ms'] > (int) $expect['max_latency_ms']) {
            $failures[] = "turn {$number}: latency {$report['latency_ms']}ms over limit";
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $violations
     */
    private function requirement(string $requirement, string $reply, array $report, array $violations): ?string
    {
        return match ($requirement) {
            'safe_resolution' => ! empty($violations) && ! str_starts_with((string) ($report['resolution'] ?? ''), 'canonical_')
                ? 'unsafe resolution: '.($report['resolution'] ?? '-')
                : null,
            'handoff' => preg_match('/adriano|artista|vou passar|passo para|encaminh|avaliar/u', $reply) !== 1
                ? 'requires an explicit handoff'
                : null,
            'no_handoff' => preg_match('/adriano|artista|vou passar|passo para|encaminh/u', $reply) === 1
                ? 'handed off when it should not'
                : null,
            'no_price' => preg_match('/r\$/', $reply) === 1 ? 'quoted a price' : null,
            'no_video' => str_contains($reply, 'video') || str_contains($reply, 'vídeo') ? 'asked for a video' : null,
            'no_photo_request' => preg_match('/(manda|mandar|envia|enviar|tirar)[^.]{0,40}(foto|fotos)/u', $reply) === 1
                ? 'asked for a photo again'
                : null,
            'no_known_question' => in_array('question_known_field', $violations, true)
                ? 're-asked a known field'
                : null,
            'closed_new_tattoo' => str_contains($reply, 'nova')
                && str_contains($reply, '?')
                && preg_match('/certo|correto|isso|seria|confirma/u', $reply) === 1
                && preg_match('/cobertura|reforma|cobrir|reformar/u', $reply) !== 1
                ? null
                : 'did not close the new-tattoo confirmation',
            'ignored' => (bool) ($report['sent'] ?? false) ? 'answered a message that should be ignored' : null,
            default => 'unknown requirement '.$requirement,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function finalState(string $artistId, string $chatId): array
    {
        $leadId = DB::table('chat_sessions')
            ->where('artist_id', $artistId)
            ->where('chat_id', $chatId)
            ->value('lead_id');

        if (! $leadId) {
            return [];
        }

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $project = DB::table('projects')->where('lead_id', $leadId)->first();

        return [
            'lead_id' => $leadId,
            'agent_blocked' => $lead?->agent_blocked_at !== null,
            'stage_key' => $project?->stage_key,
            'tattoo_type' => $project?->tattoo_type,
            'placement' => $project?->placement,
            'body_zone' => $project?->body_zone,
        ];
    }

    /**
     * @param  array<int, mixed>  $violations
     * @return array<int, string>
     */
    private function rules(array $violations): array
    {
        return array_values(array_filter(array_map(
            fn ($violation) => is_array($violation) ? (string) ($violation['rule'] ?? '') : (string) $violation,
            $violations,
        )));
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
