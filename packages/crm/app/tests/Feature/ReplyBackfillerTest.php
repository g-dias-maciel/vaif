<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\DraftStore;
use App\Services\Agent\ReplyBackfiller;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReplyBackfillerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'key',
        ]);
    }

    public function test_it_imports_artist_messages_and_pairs_the_waiting_draft(): void
    {
        $artist = Artist::create([
            'name' => 'Backfill Artist',
            'status' => 'suspended',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'backfill-artist',
        ]);
        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Backfill Artist',
            'reply_mode' => 'draft',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'backfill-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_PENDING,
        ]);
        $draft->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $first = now()->subMinutes(15);
        $second = now()->subMinutes(14);

        Http::fake([
            'https://waha.test/*' => Http::response([
                [
                    'id' => 'true_1',
                    'fromMe' => true,
                    'source' => 'app',
                    'timestamp' => $first->timestamp,
                    'body' => 'Fica R$ 600, qual o tamanho?',
                ],
                [
                    'id' => 'true_2',
                    'fromMe' => true,
                    'source' => 'app',
                    'timestamp' => $second->timestamp,
                    'body' => 'E me manda a localização.',
                ],
                [
                    'id' => 'true_3',
                    'fromMe' => true,
                    'source' => 'api',
                    'timestamp' => $second->timestamp,
                    'body' => 'Mensagem automática da IA',
                ],
                [
                    'id' => 'false_4',
                    'fromMe' => false,
                    'source' => 'app',
                    'timestamp' => $second->timestamp,
                    'body' => 'Mensagem do lead',
                ],
            ], 200),
        ]);

        $store = new FixtureAgentStore([]);

        $result = (new ReplyBackfiller(
            new WahaService('https://waha.test', 'key'),
            $store,
            new DraftStore(),
        ))->backfill($artist, 7, false, false);

        $this->assertSame(2, $result['messages_found']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['paired']);

        $draft->refresh();
        $this->assertSame(AgentDraft::STATUS_REPLIED, $draft->status);
        $this->assertStringContainsString('qual o tamanho', (string) $draft->artist_reply);
        $this->assertStringContainsString('localização', (string) $draft->artist_reply);
        $this->assertGreaterThanOrEqual(350, (int) $draft->response_seconds);
        $this->assertLessThanOrEqual(370, (int) $draft->response_seconds);

        $this->assertCount(2, $store->artistMessages);
        $this->assertStringNotContainsString('automática', collect($store->artistMessages)->pluck('text')->implode(' '));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $artist = Artist::create([
            'name' => 'Dry Artist',
            'status' => 'suspended',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'dry-artist',
        ]);
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000001',
        ]);
        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'dry-artist:5511900000001@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Oi',
            'draft_reply' => 'Olá',
            'status' => AgentDraft::STATUS_PENDING,
        ]);
        $draft->forceFill(['created_at' => now()->subMinutes(10)])->save();

        Http::fake([
            'https://waha.test/*' => Http::response([[
                'id' => 'true_1',
                'fromMe' => true,
                'source' => 'app',
                'timestamp' => now()->subMinutes(5)->timestamp,
                'body' => 'Oi, tudo bem?',
            ]], 200),
        ]);

        $store = new FixtureAgentStore([]);

        $result = (new ReplyBackfiller(
            new WahaService('https://waha.test', 'key'),
            $store,
            new DraftStore(),
        ))->backfill($artist, 7, true, false);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['paired']);
        $this->assertSame(AgentDraft::STATUS_PENDING, $draft->fresh()->status);
        $this->assertSame([], $store->artistMessages);
    }
}
