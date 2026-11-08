<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppTurn;
use App\Models\Artist;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\Eval\ArrayTransport;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\Eval\Scenario;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyValidator;
use App\Services\Agent\TurnProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessWhatsAppTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.humanize' => false]);
    }

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Artist Job',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'job-artist',
        ]);
    }

    private function processor(FixtureAgentStore $store, ArrayTransport $transport, array $script): TurnProcessor
    {
        $ai = new ScriptedAiClient($script);

        return new TurnProcessor(
            $store,
            $ai,
            new PromptBuilder(),
            new PreflightClassifier($ai, 'scripted'),
            new ClaimExtractor($ai, 'scripted'),
            new ReplyValidator(),
            $transport,
        );
    }

    private function scenario(): Scenario
    {
        return Scenario::fromFile(base_path('tests/Agent/Scenarios/04-valid-price.json'));
    }

    public function test_a_stale_job_leaves_the_buffer_to_the_newest_message(): void
    {
        $artist = $this->artist();
        $scenario = $this->scenario();
        $store = new FixtureAgentStore($scenario->data);
        $transport = new ArrayTransport();

        $store->state = [
            'pending' => 'Pode me passar o valor?',
            'last_msg_at' => date('c'),
            'last_typing_at' => null,
            'typing_state' => null,
        ];

        $job = new ProcessWhatsAppTurn($artist->id, 'job-artist:5511@c.us', '5511', 'Ana', date('c', time() - 30));
        $job->handle($store, $this->processor($store, $transport, $scenario->script()));

        $this->assertSame([], $transport->sent);
        $this->assertSame([], $store->cleared);
        $this->assertSame([], $store->applied);
    }

    public function test_a_chat_owned_by_the_artist_is_cleared_without_answering(): void
    {
        $artist = $this->artist();
        $scenario = $this->scenario();
        $store = new FixtureAgentStore($scenario->data);
        $transport = new ArrayTransport();
        $store->owner = 'artist';

        $msgAt = date('c', time() - 30);
        $store->state = [
            'pending' => 'Pode me passar o valor?',
            'last_msg_at' => $msgAt,
            'last_typing_at' => null,
            'typing_state' => null,
        ];

        $job = new ProcessWhatsAppTurn($artist->id, 'job-artist:5511@c.us', '5511', 'Ana', $msgAt);
        $job->handle($store, $this->processor($store, $transport, $scenario->script()));

        $this->assertSame([], $transport->sent);
        $this->assertSame(['job-artist:5511@c.us'], $store->cleared);
    }

    public function test_a_quiet_buffer_runs_the_grounded_turn(): void
    {
        $artist = $this->artist();
        $scenario = $this->scenario();
        $store = new FixtureAgentStore($scenario->data);
        $transport = new ArrayTransport();

        $msgAt = date('c', time() - 30);
        $store->state = [
            'pending' => "Pode me passar o valor?\n[[REF_FOTO:https://waha.test/1.jpg]]",
            'last_msg_at' => $msgAt,
            'last_typing_at' => null,
            'typing_state' => 'paused',
        ];

        $job = new ProcessWhatsAppTurn($artist->id, 'job-artist:5511@c.us', '5511', 'Diego', $msgAt);

        $job->handle($store, $this->processor($store, $transport, $scenario->script()));

        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString('R$ 600,00', $transport->sent[0]);
        $this->assertSame('Pode me passar o valor?', $store->applied[0]['lead_text']);
        $this->assertSame(['job-artist:5511@c.us'], $store->cleared);
    }
}
