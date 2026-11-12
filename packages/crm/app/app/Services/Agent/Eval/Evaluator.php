<?php

namespace App\Services\Agent\Eval;

use App\Services\Agent\AiClient;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\DraftComparator;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyComposer;
use App\Services\Agent\ReplyValidator;
use App\Services\Agent\TurnProcessor;

/**
 * Runs one fake case through the real pipeline and checks the result.
 */
class Evaluator
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model = 'eval',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Scenario $scenario): array
    {
        config(['agent.humanize' => false]);

        $artist = $scenario->artist();
        $store = new FixtureAgentStore($scenario->data);
        $transport = new ArrayTransport();

        $prompts = new PromptBuilder();
        $processor = new TurnProcessor(
            $store,
            new ReplyComposer(
                $this->ai,
                $prompts,
                new ClaimExtractor($this->ai, $this->model),
                new ReplyValidator(),
            ),
            $prompts,
            new PreflightClassifier($this->ai, $this->model),
            $transport,
        );

        $startedAt = microtime(true);

        try {
            $result = $processor->process(
                $artist,
                $scenario->chatId(),
                $scenario->phone(),
                $scenario->message(),
                $scenario->leadName(),
                true,
            );
        } catch (\Throwable $e) {
            return [
                'scenario' => $scenario->name(),
                'pass' => false,
                'failures' => ['exception: '.$e->getMessage()],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $validation = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $rules = array_values(array_filter(array_map(
            fn ($v) => is_array($v) ? (string) ($v['rule'] ?? '') : (string) $v,
            $validation['violations'] ?? [],
        )));

        $originalRules = array_values(array_filter(array_map(
            fn ($v) => is_array($v) ? (string) ($v['rule'] ?? '') : (string) $v,
            $validation['original_violations'] ?? [],
        )));

        $report = [
            'scenario' => $scenario->name(),
            'message' => $scenario->message(),
            'intent' => $result['preflight']['intent'] ?? null,
            'resolution' => $validation['resolution'] ?? ($result['skipped'] ?? null),
            'reply' => $result['reply'] ?? ($validation['final_reply'] ?? ''),
            'ok' => $validation['ok'] ?? null,
            'violations' => $rules,
            'original_violations' => $originalRules,
            'repaired' => (bool) ($result['repaired'] ?? false),
            'sent' => $transport->sent,
            'offered_slots' => count($validation['offered_slots'] ?? []),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $failures = $this->check($scenario->expect(), $report, $result);

        // A real-conversation regression also judges the new reply against the
        // artist's actual answer (or the staff media description).
        if ($scenario->artistReply() !== null) {
            try {
                $review = (new DraftComparator(
                    $this->ai,
                    new ClaimExtractor($this->ai, $this->model),
                    new ReplyValidator(),
                ))->review(
                    is_array($result['facts'] ?? null) ? $result['facts'] : $scenario->facts(),
                    $scenario->message(),
                    (string) $report['reply'],
                    (string) $scenario->artistReply(),
                    $scenario->history(),
                );

                $report['judge'] = $review['judge'];
            } catch (\Throwable $e) {
                $report['judge_error'] = $e->getMessage();
            }
        }

        // Methodology requirements are deterministic. The LLM judge is kept in
        // the report as a quality signal, but it is not the pass/fail oracle:
        // it often prefers the artist's private audio details.
        $failures = array_merge($failures, $this->checkRequirements($scenario->expect()['require'] ?? [], $report));

        $report['failures'] = $failures;
        $report['pass'] = $failures === [];

        return $report;
    }

    /**
     * @param  array<int, string>  $requirements
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function checkRequirements(array $requirements, array $report): array
    {
        $failures = [];
        $reply = $this->norm((string) ($report['reply'] ?? ''));
        $violations = array_merge((array) ($report['violations'] ?? []), (array) ($report['original_violations'] ?? []));

        foreach ($requirements as $requirement) {
            switch ((string) $requirement) {
                case 'safe_resolution':
                    $resolution = (string) ($report['resolution'] ?? '');
                    if (! empty($report['violations']) && ! str_starts_with($resolution, 'canonical_')) {
                        $failures[] = 'unsafe resolution: '.$resolution;
                    }
                    break;
                case 'handoff':
                    if (preg_match('/adriano|artista|vou passar|passo para|encaminh|avaliar/u', $reply) !== 1) {
                        $failures[] = 'requires an explicit handoff';
                    }
                    break;
                case 'video':
                    if (! str_contains($reply, 'video') && ! str_contains($reply, 'vídeo')) {
                        $failures[] = 'requires a video request';
                    }
                    break;
                case 'photos':
                    if (! str_contains($reply, 'foto')) {
                        $failures[] = 'requires a photo request';
                    }
                    break;
                case 'laser':
                    if (! str_contains($reply, 'laser')) {
                        $failures[] = 'requires the laser answer';
                    }
                    break;
                case 'no_known_question':
                    if (in_array('question_known_field', $violations, true)) {
                        $failures[] = 're-asked a known field';
                    }
                    break;
                case 'no_price':
                    if (preg_match('/r\$/', $reply) === 1) {
                        $failures[] = 'quoted a price';
                    }
                    break;
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $expect
     * @param  array<string, mixed>  $review
     * @return array<int, string>
     */
    private function checkJudge(array $expect, array $review): array
    {
        if ($expect === []) {
            return [];
        }

        $judge = is_array($review['judge'] ?? null) ? $review['judge'] : null;

        if ($judge === null) {
            return ['judge did not return a verdict'];
        }

        $failures = [];

        if (array_key_exists('acceptable', $expect) && (bool) $expect['acceptable'] !== (bool) ($judge['acceptable'] ?? false)) {
            $failures[] = 'judge acceptable='.($judge['acceptable'] ? 'true' : 'false');
        }

        if (array_key_exists('missing_handoff', $expect) && (bool) $expect['missing_handoff'] !== (bool) ($judge['missing_handoff'] ?? false)) {
            $failures[] = 'judge missing_handoff='.(($judge['missing_handoff'] ?? false) ? 'true' : 'false');
        }

        if (isset($expect['verdict']) && (string) $expect['verdict'] !== (string) ($judge['verdict'] ?? '')) {
            $failures[] = 'judge verdict '.($judge['verdict'] ?? '?').', expected '.$expect['verdict'];
        }

        foreach ((array) ($expect['verdict_not'] ?? []) as $verdict) {
            if ((string) ($judge['verdict'] ?? '') === (string) $verdict) {
                $failures[] = 'judge verdict must not be '.$verdict;
            }
        }

        return $failures;
    }

    /**
     * @return array<int, string>
     */
    private function check(array $expect, array $report, array $result): array
    {
        $failures = [];
        $reply = $this->norm((string) $report['reply']);

        if (array_key_exists('ok', $expect) && (bool) $expect['ok'] !== (bool) $report['ok']) {
            $failures[] = 'expected ok='.($expect['ok'] ? 'true' : 'false').', got '.var_export($report['ok'], true);
        }

        if (isset($expect['resolution']) && (string) $expect['resolution'] !== (string) $report['resolution']) {
            $failures[] = 'expected resolution '.$expect['resolution'].', got '.$report['resolution'];
        }

        if (isset($expect['intent']) && (string) $expect['intent'] !== (string) $report['intent']) {
            $failures[] = 'expected intent '.$expect['intent'].', got '.$report['intent'];
        }

        if (array_key_exists('repaired', $expect) && (bool) $expect['repaired'] !== $report['repaired']) {
            $failures[] = 'expected repaired='.($expect['repaired'] ? 'true' : 'false');
        }

        if (array_key_exists('sent', $expect) && (bool) $expect['sent'] !== ($report['sent'] !== [])) {
            $failures[] = 'expected sent='.($expect['sent'] ? 'true' : 'false');
        }

        if (isset($expect['max_slots']) && $report['offered_slots'] > (int) $expect['max_slots']) {
            $failures[] = 'offered '.$report['offered_slots'].' slots, max '.$expect['max_slots'];
        }

        foreach ((array) ($expect['violations'] ?? []) as $rule) {
            if (! in_array((string) $rule, $report['violations'], true)) {
                $failures[] = "expected validator rule '{$rule}'";
            }
        }

        foreach ((array) ($expect['original_violations'] ?? []) as $rule) {
            if (! in_array((string) $rule, $report['original_violations'], true)) {
                $failures[] = "expected original validator rule '{$rule}'";
            }
        }

        foreach ((array) ($expect['reply_contains'] ?? []) as $needle) {
            if (! str_contains($reply, $this->norm((string) $needle))) {
                $failures[] = "reply must contain '{$needle}'";
            }
        }

        foreach ((array) ($expect['reply_not_contains'] ?? []) as $needle) {
            if (str_contains($reply, $this->norm((string) $needle))) {
                $failures[] = "reply must not contain '{$needle}'";
            }
        }

        if (isset($expect['max_latency_ms']) && $report['latency_ms'] > (int) $expect['max_latency_ms']) {
            $failures[] = 'latency '.$report['latency_ms'].'ms over limit';
        }

        return $failures;
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
