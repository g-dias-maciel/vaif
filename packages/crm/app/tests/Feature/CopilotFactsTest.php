<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Agent\CalibrationReport;
use App\Services\Copilot\OpsFacts;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CopilotFactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'key',
            'transport.waha_webhook_url' => 'https://crm.test/api/webhooks/waha',
            'transport.n8n_webhook_url' => 'https://n8n.test/webhook/waha-webhook',
            'copilot.windows.ops_hours' => 24,
            'copilot.windows.calibration_days' => 7,
            'copilot.windows.gates_days' => 30,
            'copilot.thresholds.handoff_warning_hours' => 1,
            'copilot.thresholds.handoff_critical_hours' => 4,
            'copilot.thresholds.stuck_draft_hours' => 24,
            'copilot.thresholds.stuck_replied_hours' => 2,
        ]);
    }

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Copilot Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'copilot-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Copilot Artist',
            'reply_mode' => 'draft',
        ]);

        return $artist;
    }

    public function test_ops_facts_collect_queue_webhook_handoffs_stuck_drafts_and_drops(): void
    {
        $artist = $this->artist();

        Http::fake([
            'https://waha.test/api/sessions/copilot-artist' => Http::response([
                'name' => 'copilot-artist',
                'status' => 'WORKING',
                'me' => ['pushName' => 'Eva'],
                'config' => ['webhooks' => [[
                    'url' => 'https://crm.test/api/webhooks/waha',
                    'events' => ['message'],
                    'customHeaders' => [['name' => 'X-VAIF-Webhook-Token', 'value' => 'secret']],
                ]]],
            ], 200),
        ]);

        DB::table('jobs')->insert([
            'queue' => 'agent',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->timestamp,
            'created_at' => now()->subMinutes(15)->timestamp,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'agent',
            'payload' => '{}',
            'exception' => 'RuntimeException: boom',
            'failed_at' => now()->subMinutes(5),
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead Copilot',
            'phone_e164' => '+5511900000000',
        ]);

        Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_artista',
            'handoff_reason' => 'cover_up',
            'handoff_at' => now()->subHours(5),
        ]);

        $stuck = AgentDraft::create([
            'artist_id' => $artist->id,
            'chat_id' => 'copilot-artist:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Oi',
            'draft_reply' => 'Olá',
            'status' => AgentDraft::STATUS_PENDING,
        ]);
        $stuck->forceFill(['created_at' => now()->subHours(25)])->save();

        DB::table('agent_ingest_drops')->insert([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'session_slug' => 'copilot-artist',
            'reason' => 'artist_suspended',
            'payload' => '{}',
            'created_at' => now()->subHour(),
        ]);

        $facts = (new OpsFacts(new WahaService('https://waha.test', 'key'), new CalibrationReport()))->collect();

        $this->assertSame(1, $facts['queue']['pending']);
        $this->assertGreaterThanOrEqual(14, $facts['queue']['oldest_age_minutes']);
        $this->assertSame(1, $facts['failed_jobs']['recent']);
        $this->assertSame('crm', $facts['webhooks'][0]['webhook']);
        $this->assertTrue($facts['webhooks'][0]['reachable']);
        $this->assertSame(1, $facts['handoffs']['open']);
        $this->assertSame(1, $facts['handoffs']['over_critical']);
        $this->assertSame(1, $facts['stuck_drafts']['pending_over_threshold']);
        $this->assertSame(1, $facts['ingest_drops']['total']);
    }

    public function test_calibration_facts_collect_rules_missing_and_unacceptable_samples(): void
    {
        $artist = $this->artist();

        AgentDraft::create([
            'artist_id' => $artist->id,
            'chat_id' => 'copilot-artist:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'intent' => 'ask_price',
            'draft_reply' => 'Fica R$ 600.',
            'claims' => [],
            'validation' => [
                'ok' => false,
                'resolution' => 'canonical_price',
                'original_violations' => [['rule' => 'price_before_qualification']],
            ],
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => 'Fica R$ 600, qual o tamanho?',
            'artist_replied_at' => now()->subMinutes(5),
            'artist_validation' => ['ok' => false, 'violations' => [['rule' => 'price_before_qualification']]],
            'comparison' => [
                'deterministic' => ['ok' => false, 'violations' => ['price_before_qualification']],
                'judge' => [
                    'acceptable' => false,
                    'covered_lead_question' => false,
                    'missing' => ['perguntou o tamanho'],
                    'missing_handoff' => false,
                    'tone' => 3,
                    'verdict' => 'artist_better',
                    'rationale' => 'O artista pediu o tamanho antes.',
                ],
            ],
        ]);

        $facts = (new \App\Services\Copilot\CalibrationFacts(new CalibrationReport()))->collect();

        $this->assertSame(1, $facts['totals']['drafts']);
        $this->assertSame(1, $facts['totals']['unacceptable']);
        $this->assertSame('price_before_qualification', $facts['artists'][0]['top_violation_rules'][0]['rule']);
        $this->assertSame('perguntou o tamanho', $facts['artists'][0]['top_missing'][0]['missing']);
        $this->assertCount(1, $facts['artists'][0]['samples']);
    }
}
