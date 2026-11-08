<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\DraftProcessor;
use App\Services\Agent\DraftStore;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\Eval\Scenario;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyComposer;
use App\Services\Agent\ReplyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function processor(FixtureAgentStore $store, ScriptedAiClient $ai, DraftStore $drafts): DraftProcessor
    {
        $prompts = new PromptBuilder();

        return new DraftProcessor(
            $store,
            new ReplyComposer(
                $ai,
                $prompts,
                new ClaimExtractor($ai, 'scripted'),
                new ReplyValidator(),
            ),
            $prompts,
            new PreflightClassifier($ai, 'scripted'),
            $drafts,
        );
    }

    public function test_a_draft_is_stored_and_nothing_is_applied_or_sent(): void
    {
        $scenario = Scenario::fromFile(base_path('tests/Agent/Scenarios/04-valid-price.json'));
        $store = new FixtureAgentStore($scenario->data);
        $ai = new ScriptedAiClient($scenario->script());
        $drafts = new DraftStore();

        $result = $this->processor($store, $ai, $drafts)->draft(
            $scenario->artist(),
            $scenario->chatId(),
            $scenario->phone(),
            $scenario->message(),
            'Diego',
            date('c', time() - 30),
        );

        $this->assertSame('accepted', $result['validation']['resolution']);
        $this->assertSame([], $store->applied);
        $this->assertSame([], $store->remembered);
        $this->assertCount(1, $store->rememberedHumans);

        $draft = AgentDraft::firstOrFail();
        $this->assertSame(AgentDraft::STATUS_PENDING, $draft->status);
        $this->assertStringContainsString('R$ 600,00', $draft->draft_reply);
        $this->assertSame('ask_price', $draft->intent);
        $this->assertNotEmpty($draft->prompt_version);
        $this->assertNotEmpty($draft->facts);
    }

    public function test_a_selected_slot_is_simulated_without_writing_a_booking(): void
    {
        $scenario = Scenario::fromFile(base_path('tests/Agent/Scenarios/11-valid-booking-confirmation.json'));
        $store = new FixtureAgentStore($scenario->data);
        $ai = new ScriptedAiClient($scenario->script());
        $drafts = new DraftStore();

        $result = $this->processor($store, $ai, $drafts)->draft(
            $scenario->artist(),
            $scenario->chatId(),
            $scenario->phone(),
            $scenario->message(),
            'João',
            date('c', time() - 30),
        );

        $this->assertSame('2026-11-10T17:00:00+00:00', $result['simulated_actions']['booking']['start_at']);
        $this->assertSame([], $store->applied);

        $draft = AgentDraft::firstOrFail();
        $this->assertTrue($draft->facts['booking']['created_this_turn']);
        $this->assertSame(18000, $draft->simulated_actions['deposit_cents']);
    }

    public function test_the_same_turn_does_not_create_two_drafts(): void
    {
        $scenario = Scenario::fromFile(base_path('tests/Agent/Scenarios/04-valid-price.json'));
        $store = new FixtureAgentStore($scenario->data);
        $drafts = new DraftStore();
        $processor = $this->processor($store, new ScriptedAiClient($scenario->script()), $drafts);

        $processor->draft($scenario->artist(), $scenario->chatId(), $scenario->phone(), $scenario->message(), 'Diego');
        $second = $processor->draft($scenario->artist(), $scenario->chatId(), $scenario->phone(), $scenario->message(), 'Diego');

        $this->assertSame('duplicate_draft', $second['skipped']);
        $this->assertSame(1, AgentDraft::count());
    }
}
