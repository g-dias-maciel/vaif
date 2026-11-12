<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\DraftComparator;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\ReplyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DraftComparatorTest extends TestCase
{
    use RefreshDatabase;

    private function draft(array $overrides = []): AgentDraft
    {
        return AgentDraft::create(array_merge([
            'artist_id' => (string) Str::uuid(),
            'chat_id' => 'agent:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Pode me passar o valor?',
            'intent' => 'ask_price',
            'facts' => [
                'pricing' => ['status' => 'ready', 'base_cents' => 60000, 'offered_cents' => 60000, 'deposit_cents' => 18000],
                'availability' => ['status' => 'ready', 'slots' => []],
                'project' => ['tattoo_type' => 'new', 'placement' => 'braço', 'body_zone' => 'área média', 'style' => 'fine line'],
                'booking' => ['active' => false, 'created_this_turn' => false],
                'conditions' => ['pix_available' => true],
                'studio' => ['city' => 'São Paulo', 'timezone' => 'America/Sao_Paulo'],
                'turn' => ['intent' => 'ask_price'],
            ],
            'draft_reply' => 'Fica R$ 600,00 à vista.',
            'claims' => [['type' => 'price', 'cents' => 60000]],
            'validation' => ['ok' => true, 'resolution' => 'accepted'],
            'status' => AgentDraft::STATUS_REPLIED,
            'artist_reply' => 'Fica R$ 600,00, quer ver os horários?',
            'artist_replied_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(3),
        ], $overrides));
    }

    public function test_it_compares_the_artist_reply_with_the_draft_facts(): void
    {
        $draft = $this->draft();

        $ai = new ScriptedAiClient([
            'claims' => ['claims' => [['type' => 'price', 'cents' => 60000]]],
            'judge' => [
                'acceptable' => true,
                'covered_lead_question' => true,
                'missing' => [],
                'factual_conflicts' => [],
                'missing_handoff' => false,
                'tone' => 4,
                'verdict' => 'draft_better',
                'rationale' => 'Mesmo preço e próximo passo correto.',
            ],
        ]);

        $comparison = (new DraftComparator(
            $ai,
            new ClaimExtractor($ai, 'scripted'),
            new ReplyValidator(),
        ))->compare($draft);

        $this->assertTrue($comparison['deterministic']['ok']);
        $this->assertTrue($comparison['judge']['acceptable']);
        $this->assertSame('draft_better', $comparison['judge']['verdict']);

        $draft->refresh();
        $this->assertSame(AgentDraft::STATUS_COMPARED, $draft->status);
        $this->assertSame('draft_better', $draft->judgeVerdict());
        $this->assertTrue($draft->wasAccepted());
    }

    public function test_a_staff_media_note_makes_the_pair_comparable(): void
    {
        $draft = $this->draft([
            'artist_reply' => '[MÍDIA ENVIADA]',
            'artist_media_note' => 'Mandou a tabela de preços, R$ 600 no braço.',
        ]);

        $ai = new ScriptedAiClient([
            'claims' => ['claims' => [['type' => 'price', 'cents' => 60000]]],
            'judge' => [
                'acceptable' => true,
                'covered_lead_question' => true,
                'missing' => [],
                'factual_conflicts' => [],
                'missing_handoff' => false,
                'tone' => 4,
                'verdict' => 'tie',
                'rationale' => 'Mídia descrita pela equipe.',
            ],
        ]);

        $comparison = (new DraftComparator(
            $ai,
            new ClaimExtractor($ai, 'scripted'),
            new ReplyValidator(),
        ))->compare($draft);

        $this->assertTrue($comparison['comparable']);
        $this->assertSame('artist_media_note', $comparison['reason']);
        $this->assertSame('tie', $comparison['judge']['verdict']);
    }

    public function test_review_judges_without_persisting(): void
    {
        $draft = $this->draft();

        $ai = new ScriptedAiClient([
            'claims' => ['claims' => [['type' => 'price', 'cents' => 60000]]],
            'judge' => [
                'acceptable' => true,
                'covered_lead_question' => true,
                'missing' => [],
                'factual_conflicts' => [],
                'missing_handoff' => false,
                'tone' => 4,
                'verdict' => 'tie',
                'rationale' => 'Equivalente.',
            ],
        ]);

        $review = (new DraftComparator(
            $ai,
            new ClaimExtractor($ai, 'scripted'),
            new ReplyValidator(),
        ))->review(
            $draft->facts,
            $draft->lead_text,
            'Fica R$ 600,00 à vista.',
            'Fica R$ 600,00, quer ver os horários?',
            [['role' => 'user', 'content' => 'Quanto fica?']],
        );

        $this->assertTrue($review['comparable']);
        $this->assertTrue($review['judge']['acceptable']);

        $draft->refresh();
        $this->assertSame(AgentDraft::STATUS_REPLIED, $draft->status);
        $this->assertNull($draft->comparison);
    }

    public function test_a_media_only_artist_reply_is_marked_not_comparable(): void
    {
        $draft = $this->draft(['artist_reply' => '[MÍDIA ENVIADA]']);

        $ai = new ScriptedAiClient([
            'claims' => ['claims' => []],
            'judge' => ['acceptable' => true, 'verdict' => 'tie'],
        ]);

        $comparison = (new DraftComparator(
            $ai,
            new ClaimExtractor($ai, 'scripted'),
            new ReplyValidator(),
        ))->compare($draft);

        $this->assertFalse($comparison['comparable']);
        $this->assertSame('artist_reply_media_only', $comparison['reason']);
        $this->assertNull($comparison['judge']);

        $draft->refresh();
        $this->assertSame(AgentDraft::STATUS_COMPARED, $draft->status);
        $this->assertNull($draft->judgeVerdict());
        $this->assertNull($draft->wasAccepted());
    }
}
