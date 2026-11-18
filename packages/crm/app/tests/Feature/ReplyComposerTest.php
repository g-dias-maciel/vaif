<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Services\Agent\AiClient;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyComposer;
use App\Services\Agent\ReplyValidator;
use Tests\TestCase;

class ReplyComposerTest extends TestCase
{
    public function test_an_unsafe_repair_is_not_sent_over_the_canonical_reply(): void
    {
        $ai = new class implements AiClient {
            public function chat(array $messages, string $model): string
            {
                $system = (string) ($messages[0]['content'] ?? '');

                if (str_contains($system, 'Reparar a resposta')) {
                    return '{"reply":"Fica R$ 999.","claims":[{"type":"price","cents":999}]}';
                }

                return 'Fica R$ 999.';
            }
        };

        $prompts = new class extends PromptBuilder {
            public function messages(Artist $artist, array $turn, array $facts, string $leadText, array $history = []): array
            {
                return [['role' => 'user', 'content' => 'oi']];
            }
        };

        $claims = new class extends ClaimExtractor {
            public function __construct()
            {
            }

            public function extract(string $reply, array $facts): array
            {
                return [['type' => 'price', 'cents' => 999]];
            }
        };

        $validator = new class extends ReplyValidator {
            public function validate(array $facts, array $claims, string $reply, array $preflight = []): array
            {
                $bad = $reply === 'Fica R$ 999.';

                return [
                    'ok' => false,
                    'repairable' => true,
                    'final_reply' => $bad ? 'Fica R$ 600.' : $reply,
                    'resolution' => $bad ? 'canonical_price' : 'accepted',
                    'violations' => $bad ? [['rule' => 'price_not_in_facts']] : [['rule' => 'still_bad']],
                    'effective_claims' => [],
                    'offered_slots' => [],
                ];
            }
        };

        $composer = new ReplyComposer($ai, $prompts, $claims, $validator);
        $result = $composer->compose(
            new Artist(['name' => 'Adriano']),
            [],
            ['pricing' => ['status' => 'ready']],
            'quanto fica?',
            [],
            ['flags' => []],
        );

        $this->assertSame('Fica R$ 600.', $result['reply']);
        $this->assertFalse($result['validation']['ok']);
    }
}
