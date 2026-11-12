<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Services\Agent\AgentStore;
use App\Services\Agent\ArtistSignalExtractor;
use App\Services\Agent\ConversationBackfiller;
use App\Services\Agent\ConversationSummarizer;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\ObservedStore;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Backfill Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'backfill-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Backfill Artist',
            'reply_mode' => 'observe',
        ]);

        return $artist;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messages(): array
    {
        $base = now()->subDays(10)->setTime(10, 0)->getTimestamp();

        $out = [
            ['id' => 'm1', 'fromMe' => false, 'body' => 'Oi, quero fazer no braço em blackwork', 'timestamp' => $base, 'from' => '5511900000001@s.whatsapp.net'],
            ['id' => 'm2', 'fromMe' => true, 'body' => 'Oi Ana! Qual o tamanho?', 'timestamp' => $base + 60, 'source' => 'app'],
            ['id' => 'm3', 'fromMe' => false, 'body' => 'Fechamento', 'timestamp' => $base + 120, 'from' => '5511900000001@s.whatsapp.net'],
            ['id' => 'm4', 'fromMe' => true, 'body' => 'Fica R$ 1.500 com sinal de R$ 450 no PIX.', 'timestamp' => $base + 180, 'source' => 'app'],
            ['id' => 'm5', 'fromMe' => false, 'body' => 'Pode ser sábado?', 'timestamp' => $base + 240, 'from' => '5511900000001@s.whatsapp.net'],
            ['id' => 'm6', 'fromMe' => true, 'body' => 'Fechado, reservei sábado 14h.', 'timestamp' => $base + 300, 'source' => 'app'],
        ];

        return $out;
    }

    private function waha(): WahaService
    {
        $waha = \Mockery::mock(WahaService::class);
        $waha->shouldReceive('chats')->once()->andReturn([
            ['id' => '5511900000001@s.whatsapp.net', 'name' => 'Ana'],
            ['id' => '123456-789@g.us', 'name' => 'Grupo'],
        ]);
        $waha->shouldReceive('chatMessages')
            ->once()
            ->withArgs(fn (Artist $artist, string $jid) => $jid === '5511900000001@s.whatsapp.net')
            ->andReturn($this->messages());

        return $waha;
    }

    private function script(): array
    {
        return [
            'preflight' => [
                'intent' => 'qualify',
                'entities' => [
                    'placement' => 'braco',
                    'body_zone' => 'fechamento',
                    'style' => 'blackwork',
                ],
                'assumptions' => [],
                'flags' => [],
                'confidence' => 0.9,
            ],
            'artist_signals' => [
                'price_cents' => 150000,
                'booking_confirmed_local' => now('America/Sao_Paulo')->subDays(10)->setTime(14, 0)->format('Y-m-d H:i'),
                'confidence' => 0.9,
            ],
            'summary' => 'Lead quer fechamento no braço em blackwork; orçamento de R$ 1.500.',
        ];
    }

    public function test_dry_run_writes_nothing_and_counts_the_history(): void
    {
        $artist = $this->artist();

        $agentStore = \Mockery::mock(AgentStore::class);
        $agentStore->shouldNotReceive('startTurn');

        $observedStore = \Mockery::mock(ObservedStore::class);
        $observedStore->shouldNotReceive('importMessage');

        $ai = new ScriptedAiClient([]);

        $backfiller = new ConversationBackfiller(
            $this->waha(),
            $agentStore,
            $observedStore,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
            new ArtistSignalExtractor($ai, 'scripted'),
            new ConversationSummarizer($ai, $agentStore),
        );

        $counts = $backfiller->run($artist, 90, [], 100, true);

        $this->assertTrue($counts['dry_run']);
        $this->assertSame(2, $counts['chats_fetched']);
        $this->assertSame(1, $counts['chats_considered']);
        $this->assertSame(6, $counts['messages']);
        $this->assertSame(3, $counts['inbound_messages']);
        $this->assertSame(0, $counts['imported']);
        $this->assertSame(1, $counts['skipped']['group_or_invalid']);
    }

    public function test_it_imports_messages_and_interprets_the_conversation(): void
    {
        $artist = $this->artist();

        Lead::create([
            'id' => '00000000-0000-4000-8000-000000000001',
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511900000001',
            'source' => 'whatsapp',
            'first_seen_at' => null,
        ]);

        $agentStore = new FixtureAgentStore([
            'turn' => [
                'placement' => 'braco',
                'body_zone' => 'fechamento',
                'table_price_cents' => 150000,
            ],
            'history' => [
                ['role' => 'user', 'content' => 'Oi'],
                ['role' => 'assistant', 'content' => 'Olá'],
                ['role' => 'user', 'content' => 'Braço'],
                ['role' => 'assistant', 'content' => 'Qual tamanho?'],
                ['role' => 'user', 'content' => 'Fechamento'],
                ['role' => 'assistant', 'content' => 'Fica R$ 1.500'],
            ],
        ]);

        $observedStore = \Mockery::mock(ObservedStore::class);
        $observedStore->shouldReceive('importMessage')->times(6)->andReturn(true);
        $observedStore->shouldReceive('setLeadTimeline')->once();
        $observedStore->shouldReceive('applyFields')
            ->once()
            ->withArgs(fn (string $artistId, string $projectId, string $phone, array $entities) => $entities['placement'] === 'braco'
                && $entities['body_zone'] === 'fechamento'
                && $entities['style'] === 'blackwork')
            ->andReturn(['changed' => ['placement']]);
        $observedStore->shouldReceive('writeQuote')
            ->once()
            ->withArgs(fn (string $artistId, string $projectId, int $base, int $price, string $actor) => $base === 150000
                && $price === 150000
                && $actor === 'system')
            ->andReturn(['id' => 'quote-1']);
        $observedStore->shouldReceive('setStage')->once()->withArgs(
            fn (string $artistId, string $projectId, string $stageKey, string $actor) => $stageKey === 'fechado'
                && $actor === 'system'
        );
        $observedStore->shouldReceive('setBookingCreatedAt')->once()->withArgs(
            fn (string $bookingId, ?string $createdAt, bool $sync, string $label) => $sync === false
                && $label === 'backfill'
        );
        $observedStore->shouldReceive('upsertSummary')->once();

        $ai = new ScriptedAiClient($this->script());

        $backfiller = new ConversationBackfiller(
            $this->waha(),
            $agentStore,
            $observedStore,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
            new ArtistSignalExtractor($ai, 'scripted'),
            new ConversationSummarizer($ai, $agentStore),
        );

        $counts = $backfiller->run($artist, 90, [], 100, false);

        $this->assertSame(6, $counts['imported']);
        $this->assertSame(1, $counts['interpreted']);
        $this->assertSame(1, $counts['quotes']);
        $this->assertSame(1, $counts['bookings']);
        $this->assertSame(1, $counts['summaries']);
        $this->assertCount(1, $agentStore->booked);
        $this->assertSame('system', $agentStore->booked[0]['created_by']);
    }

    public function test_excluded_phone_numbers_are_not_imported(): void
    {
        $artist = $this->artist();

        $agentStore = \Mockery::mock(AgentStore::class);
        $agentStore->shouldNotReceive('startTurn');

        $observedStore = \Mockery::mock(ObservedStore::class);
        $observedStore->shouldNotReceive('importMessage');

        $ai = new ScriptedAiClient([]);

        $backfiller = new ConversationBackfiller(
            $this->waha(),
            $agentStore,
            $observedStore,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
            new ArtistSignalExtractor($ai, 'scripted'),
            new ConversationSummarizer($ai, $agentStore),
        );

        $counts = $backfiller->run($artist, 90, ['5511900000001'], 100, false);

        $this->assertSame(0, $counts['chats_considered']);
        $this->assertSame(1, $counts['skipped']['excluded']);
    }
}
