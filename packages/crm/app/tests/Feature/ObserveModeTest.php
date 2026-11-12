<?php

namespace Tests\Feature;

use App\Jobs\ObserveArtistMessage;
use App\Jobs\SummarizeConversation;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\ObservedTurn;
use App\Services\Agent\AgentStore;
use App\Services\Agent\ConversationSummarizer;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\ObservedStore;
use App\Services\Agent\ObservedTurnProcessor;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObserveModeTest extends TestCase
{
    use RefreshDatabase;

    private FixtureAgentStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.webhook_token' => 'observe-secret', 'agent.queue' => 'agent']);

        $this->store = new FixtureAgentStore([]);
        $this->app->instance(AgentStore::class, $this->store);
    }

    private function artist(string $status = 'live', string $mode = 'observe'): Artist
    {
        $artist = Artist::create([
            'name' => 'Observe Artist',
            'status' => $status,
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'observe-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Observe Artist',
            'reply_mode' => $mode,
        ]);

        return $artist;
    }

    private function message(array $payload = [], string $event = 'message'): array
    {
        return [
            'event' => $event,
            'session' => 'observe-artist',
            'payload' => array_merge([
                'id' => 'OBS123',
                'from' => '5511900000001@c.us',
                'fromMe' => false,
                'body' => 'Oi, quero uma tattoo',
                'type' => 'chat',
                '_data' => ['key' => ['remoteJid' => '5511900000001@c.us'], 'pushName' => 'Ana'],
            ], $payload),
        ];
    }

    private function webhook(array $body)
    {
        config(['agent.webhook_token' => 'observe-secret', 'agent.queue' => 'agent']);

        return $this->withHeader('X-VAIF-Webhook-Token', 'observe-secret')
            ->postJson('/api/webhooks/waha', $body);
    }

    public function test_observe_mode_records_the_artist_side_without_sending(): void
    {
        Queue::fake();
        $artist = $this->artist();

        $this->webhook($this->message([
            'id' => 'ART999',
            'fromMe' => true,
            'from' => '5511900000000@c.us',
            'to' => '5511900000001@c.us',
            'body' => 'Oi Ana, tudo bem?',
            'source' => 'app',
            '_data' => [
                'key' => [
                    'remoteJid' => '5511900000001@c.us',
                    'fromMe' => true,
                ],
            ],
        ]))->assertOk()->assertJson(['from_me' => true]);

        Queue::assertPushed(ObserveArtistMessage::class, function (ObserveArtistMessage $job) use ($artist) {
            return $job->artistId === $artist->id
                && $job->chatId === 'observe-artist:5511900000001@c.us'
                && $job->phone === '5511900000001'
                && $job->text === 'Oi Ana, tudo bem?'
                && $job->waMessageId === 'ART999';
        });

        // The observer is queued; nothing else may be dispatched for a sent reply.
        Queue::assertNotPushed(\App\Jobs\ProcessWhatsAppTurn::class);
    }

    public function test_observe_mode_queues_inbound_observation_through_the_normal_turn(): void
    {
        Queue::fake();
        $this->artist();

        $this->webhook($this->message())->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(\App\Jobs\ProcessWhatsAppTurn::class);
        Queue::assertNotPushed(ObserveArtistMessage::class);
    }

    public function test_a_suspended_artist_is_not_observed(): void
    {
        Queue::fake();
        $this->artist('suspended');

        $this->webhook($this->message())->assertOk()->assertJson(['ignored' => 'artist_suspended']);

        Queue::assertNothingPushed();
    }

    public function test_staff_can_switch_an_artist_to_observe(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $artist = $this->artist('live', 'draft');
        $staff = \App\Models\User::create([
            'name' => 'Staff',
            'email' => 'observe-staff-'.Str::random(6).'@vaif.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => \App\Models\User::ROLE_STAFF,
        ]);

        $this->actingAs($staff)
            ->post(route('calibration.mode', $artist), ['reply_mode' => 'observe'])
            ->assertRedirect();

        $this->assertSame('observe', $artist->settings()->first()->reply_mode);
    }

    public function test_the_processor_writes_explicit_fields_and_never_sends(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $store = new FixtureAgentStore([]);

        $ai = new ScriptedAiClient([
            'preflight' => [
                'intent' => 'qualify',
                'entities' => [
                    'placement' => 'braco',
                    'style' => 'blackwork',
                    'first_tattoo' => true,
                    'tattoo_type' => 'nova',
                    'name' => 'Ana',
                ],
                'assumptions' => [],
                'flags' => [],
                'confidence' => 0.9,
            ],
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('applyFields')
            ->once()
            ->withArgs(function (string $artistId, string $projectId, string $phone, array $entities) use ($artist) {
                return $artistId === $artist->id
                    && $projectId === '00000000-0000-4000-8000-000000000002'
                    && $phone === '5511999999999'
                    // `tattoo_type` was not in the lead's words -> assumption only.
                    && $entities === [
                        'placement' => 'braco',
                        'style' => 'blackwork',
                        'first_tattoo' => true,
                        'name' => 'Ana',
                    ];
            })
            ->andReturn(['changed' => ['placement', 'style', 'first_tattoo']]);

        $observed->shouldReceive('saveTurn')->once()->andReturn(true);

        $processor = new ObservedTurnProcessor(
            $store,
            $observed,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
        );

        $result = $processor->observeInbound(
            $artist,
            'observe-artist:5511900000001@c.us',
            '5511999999999',
            'Oi, sou a Ana e quero fechar o braço em blackwork, é minha primeira tattoo',
            'Ana',
        );

        $this->assertSame('qualify', $result['intent']);
        $this->assertSame([], $store->applied, 'observe must never apply a grounded turn');
        $this->assertSame([], $store->remembered, 'observe must never write an AI reply');
        $this->assertCount(1, $store->rememberedHumans);
        Queue::assertPushed(SummarizeConversation::class);
    }

    public function test_the_summary_job_stores_the_summary_for_the_latest_message(): void
    {
        $artist = $this->artist();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511999999999',
            'source' => 'whatsapp',
            'first_seen_at' => now(),
        ]);

        $store = new FixtureAgentStore([
            'history' => [
                ['role' => 'user', 'content' => 'Quero uma tattoo no braço'],
                ['role' => 'assistant', 'content' => 'Qual o tamanho e o estilo?'],
                ['role' => 'user', 'content' => 'Médio, blackwork'],
            ],
        ]);

        $ai = new ScriptedAiClient(['summary' => 'Quer uma tattoo média no braço, em blackwork.']);
        $summarizer = new ConversationSummarizer($ai, $store);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('latestMessageAt')->once()->with((string) $lead->id)->andReturn(null);
        $observed->shouldReceive('upsertSummary')
            ->once()
            ->with(
                (string) $lead->id,
                'Quer uma tattoo média no braço, em blackwork.',
                3,
                (string) config('agent.models.summary'),
            );

        (new SummarizeConversation($artist->id, (string) $lead->id, now()->toIso8601String()))
            ->handle($observed, $summarizer);
    }

    public function test_a_stale_summary_job_does_nothing(): void
    {
        $artist = $this->artist();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511999999999',
            'source' => 'whatsapp',
            'first_seen_at' => now(),
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('latestMessageAt')
            ->once()
            ->andReturn(now()->toIso8601String());
        $observed->shouldNotReceive('upsertSummary');

        $store = new FixtureAgentStore([
            'history' => [
                ['role' => 'user', 'content' => 'Oi'],
                ['role' => 'assistant', 'content' => 'Olá'],
            ],
        ]);

        // No 'summary' script: if the job called the model, it would throw.
        $summarizer = new ConversationSummarizer(new ScriptedAiClient([]), $store);

        (new SummarizeConversation($artist->id, (string) $lead->id, now()->subMinute()->toIso8601String()))
            ->handle($observed, $summarizer);
    }

    public function test_observed_turns_are_persisted_and_deduplicated(): void
    {
        $artist = $this->artist();
        $store = new \App\Services\Agent\DatabaseObservedStore;

        $turn = [
            'artist_id' => $artist->id,
            'chat_id' => 'observe-artist:5511900000001@c.us',
            'direction' => ObservedTurn::DIRECTION_INBOUND,
            'wa_message_id' => 'OBS123',
            'occurred_at' => now()->toIso8601String(),
            'text' => 'Oi',
        ];

        $this->assertTrue($store->saveTurn($turn));
        $this->assertFalse($store->saveTurn($turn));
        $this->assertSame(1, ObservedTurn::count());
    }

    public function test_the_turn_job_uses_the_observer_and_not_the_replier(): void
    {
        $artist = $this->artist();
        $store = new FixtureAgentStore([]);

        $msgAt = date('c', time() - 30);
        $store->state = [
            'pending' => 'Oi, quero uma tattoo',
            'last_msg_at' => $msgAt,
            'last_typing_at' => null,
            'typing_state' => 'paused',
        ];

        $replier = \Mockery::mock(\App\Services\Agent\TurnProcessor::class);
        $replier->shouldNotReceive('process');

        $observer = \Mockery::mock(ObservedTurnProcessor::class);
        $observer->shouldReceive('observeInbound')
            ->once()
            ->andReturn(['lead_id' => '00000000-0000-4000-8000-000000000001']);

        $job = new \App\Jobs\ProcessWhatsAppTurn(
            $artist->id,
            'observe-artist:5511900000001@c.us',
            '5511900000001',
            'Ana',
            $msgAt,
        );

        $job->handle($store, $replier, null, $observer);

        $this->assertSame(['observe-artist:5511900000001@c.us'], $store->cleared);
    }

    public function test_the_lead_page_shows_the_observed_summary(): void
    {
        $artist = $this->artist();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511999999999',
            'source' => 'whatsapp',
            'first_seen_at' => now(),
        ]);

        \App\Models\LeadSummary::create([
            'lead_id' => $lead->id,
            'body' => 'Quer fechar o braço em blackwork; aguardando o sinal.',
            'message_count' => 8,
            'generated_at' => now(),
        ]);

        $user = \App\Models\User::create([
            'name' => 'Artist User',
            'email' => 'observe-lead-'.Str::random(6).'@vaif.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => \App\Models\User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($user)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Resumo da conversa')
            ->assertSee('Quer fechar o braço em blackwork');
    }

    public function test_artist_signals_write_a_quote_and_a_deposit_request(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $projectId = '00000000-0000-4000-8000-000000000002';

        $store = new FixtureAgentStore([
            'turn' => [
                'stage_key' => 'qualificando',
                'placement' => 'braco',
                'body_zone' => 'fechamento',
                'table_price_cents' => 150000,
                'deposit_amount_cents' => 45000,
                'active_booking_id' => '00000000-0000-4000-8000-0000000000b9',
            ],
        ]);

        $ai = new ScriptedAiClient([
            'artist_signals' => [
                'offered_slots' => [[
                    'local' => now('America/Sao_Paulo')->addDays(2)->setTime(14, 0)->format('Y-m-d H:i'),
                    'label' => 'sábado 14h',
                ]],
                'price_cents' => 150000,
                'asks_for_deposit' => true,
                'deposit_cents' => 45000,
                'confidence' => 0.9,
            ],
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('saveTurn')->once()->andReturn(true);
        $observed->shouldReceive('writeQuote')
            ->once()
            ->with($artist->id, $projectId, 150000, 150000, 'artist')
            ->andReturn(['id' => 'quote-1']);
        $observed->shouldReceive('requestDeposit')
            ->once()
            ->with($artist->id, $projectId, 45000, 'pix', 'artist')
            ->andReturn(['id' => 'payment-1']);
        $observed->shouldNotReceive('createWrite');

        $processor = new ObservedTurnProcessor(
            $store,
            $observed,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
            null,
            new \App\Services\Agent\ArtistSignalExtractor($ai, 'scripted'),
        );

        $result = $processor->observeArtist(
            $artist,
            'observe-artist:5511900000001@c.us',
            '5511999999999',
            'Tenho sábado 14h. Fica R$ 1.500 com sinal de R$ 450 no PIX.',
            'ART123',
            '2026-11-12T12:00:00-03:00',
        );

        $this->assertSame('quote-1', $result['applied']['quote']);
        $this->assertSame('payment-1', $result['applied']['deposit_request']);
        $this->assertCount(1, $store->chatPatches);
        $this->assertSame('O1', $store->chatPatches[0]['offered_slots'][0]['id']);
        $this->assertCount(0, $store->booked);
    }

    public function test_lead_accepting_an_offered_slot_books_automatically(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $slot = now()->addDays(2)->setTime(17, 0)->toIso8601String();

        $store = new FixtureAgentStore([
            'turn' => [
                'placement' => 'braco',
                'body_zone' => 'fechamento',
                'agent_state' => [
                    'offered_slots' => [
                        ['id' => 'O1', 'start_at' => $slot, 'label' => 'sábado 14h'],
                    ],
                ],
            ],
        ]);

        $ai = new ScriptedAiClient([
            'preflight' => [
                'intent' => 'choose_slot',
                'entities' => ['selected_slot_start' => $slot],
                'assumptions' => [],
                'flags' => [],
                'confidence' => 0.9,
            ],
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('saveTurn')->once()->andReturn(true);
        $observed->shouldReceive('labelBooking')
            ->once()
            ->with('00000000-0000-4000-8000-0000000000b1', 'artist_observed');
        $observed->shouldReceive('createWrite')
            ->once()
            ->withArgs(fn (array $write) => $write['kind'] === 'booking'
                && $write['status'] === 'auto_applied'
                && $write['payload']['start_at'] === $slot)
            ->andReturn(new \App\Models\ObservedWrite(['id' => 'write-1', 'status' => 'auto_applied']));

        $processor = new ObservedTurnProcessor(
            $store,
            $observed,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
        );

        $processor->observeInbound(
            $artist,
            'observe-artist:5511900000001@c.us',
            '5511999999999',
            'Pode ser sábado',
            'Ana',
        );

        $this->assertCount(1, $store->booked);
        $this->assertSame($slot, $store->booked[0]['start_at']);
    }

    public function test_an_artist_confirmation_queues_a_booking_review(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $local = now('America/Sao_Paulo')->addDays(2)->setTime(14, 0)->format('Y-m-d H:i');
        $slot = \Illuminate\Support\Carbon::parse($local, 'America/Sao_Paulo')->toIso8601String();

        $store = new FixtureAgentStore([]);

        $ai = new ScriptedAiClient([
            'artist_signals' => [
                'booking_confirmed_local' => $local,
                'confidence' => 0.9,
            ],
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('saveTurn')->once()->andReturn(true);
        $observed->shouldReceive('createWrite')
            ->once()
            ->withArgs(fn (array $write) => $write['kind'] === 'booking'
                && $write['status'] === 'pending'
                && $write['payload']['start_at'] === $slot)
            ->andReturn(new \App\Models\ObservedWrite(['id' => 'write-2', 'status' => 'pending']));
        $observed->shouldNotReceive('writeQuote');

        $processor = new ObservedTurnProcessor(
            $store,
            $observed,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
            null,
            new \App\Services\Agent\ArtistSignalExtractor($ai, 'scripted'),
        );

        $result = $processor->observeArtist(
            $artist,
            'observe-artist:5511900000001@c.us',
            '5511999999999',
            'Fechado, reservei sábado 14h para você.',
            'ART124',
            '2026-11-12T12:00:00-03:00',
        );

        $this->assertSame('pending', $result['applied']['booking_review']['status']);
        $this->assertCount(0, $store->booked);
    }

    public function test_approving_a_booking_write_applies_it(): void
    {
        $artist = $this->artist();

        $write = \App\Models\ObservedWrite::create([
            'artist_id' => $artist->id,
            'project_id' => '00000000-0000-4000-8000-000000000002',
            'kind' => \App\Models\ObservedWrite::KIND_BOOKING,
            'status' => \App\Models\ObservedWrite::STATUS_PENDING,
            'dedupe_key' => 'booking:test:1',
            'payload' => ['start_at' => '2026-11-14T17:00:00+00:00'],
            'evidence' => ['message' => 'Pode ser sábado'],
        ]);

        $applier = new \App\Services\Agent\ObservedWriteApplier(
            $this->store,
            $this->app->make(ObservedStore::class),
        );

        $result = $applier->approve($write, null);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->store->booked);
        $this->assertSame(\App\Models\ObservedWrite::STATUS_APPROVED, $write->fresh()->status);
    }

    public function test_an_artist_can_only_decide_their_own_observed_write(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $artist = $this->artist();
        $other = Artist::create([
            'name' => 'Other Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'other-artist',
        ]);

        $owner = \App\Models\User::create([
            'name' => 'Owner',
            'email' => 'observe-owner-'.Str::random(6).'@vaif.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => \App\Models\User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $stranger = \App\Models\User::create([
            'name' => 'Stranger',
            'email' => 'observe-stranger-'.Str::random(6).'@vaif.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => \App\Models\User::ROLE_ARTIST,
            'artist_id' => $other->id,
        ]);

        $write = \App\Models\ObservedWrite::create([
            'artist_id' => $artist->id,
            'project_id' => '00000000-0000-4000-8000-000000000002',
            'kind' => \App\Models\ObservedWrite::KIND_BOOKING,
            'status' => \App\Models\ObservedWrite::STATUS_PENDING,
            'dedupe_key' => 'booking:test:2',
            'payload' => ['start_at' => '2026-11-14T17:00:00+00:00'],
        ]);

        $this->actingAs($stranger)
            ->post(route('observed-writes.decide', $write), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('observed-writes.decide', $write), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame(\App\Models\ObservedWrite::STATUS_APPROVED, $write->fresh()->status);
    }

    public function test_a_lead_claiming_the_pix_creates_a_confirmation_review(): void
    {
        Queue::fake();

        $artist = $this->artist();

        $store = new FixtureAgentStore([
            'turn' => [
                'deposit_status' => 'aguardando_confirmacao',
                'placement' => 'braco',
                'body_zone' => 'fechamento',
            ],
        ]);

        $ai = new ScriptedAiClient([
            'preflight' => [
                'intent' => 'other',
                'entities' => [],
                'assumptions' => [],
                'flags' => [],
                'confidence' => 0.8,
            ],
        ]);

        $observed = \Mockery::mock(ObservedStore::class);
        $observed->shouldReceive('saveTurn')->once()->andReturn(true);
        $observed->shouldReceive('createWrite')
            ->once()
            ->withArgs(fn (array $write) => $write['kind'] === 'deposit_confirm'
                && $write['status'] === 'pending'
                && $write['payload']['method'] === 'pix')
            ->andReturn(new \App\Models\ObservedWrite(['id' => 'write-3', 'status' => 'pending']));

        $processor = new ObservedTurnProcessor(
            $store,
            $observed,
            new PreflightClassifier($ai, 'scripted'),
            new PromptBuilder,
        );

        $result = $processor->observeInbound(
            $artist,
            'observe-artist:5511900000001@c.us',
            '5511999999999',
            'Fiz o pix agora, pode conferir',
            'Ana',
        );

        $this->assertSame('pending', $result['applied']['deposit_confirm_review']['status']);
        $this->assertCount(0, $store->booked);
    }

    public function test_the_lead_page_shows_pending_observed_writes(): void
    {
        $artist = $this->artist();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511999999999',
            'source' => 'whatsapp',
            'first_seen_at' => now(),
        ]);

        $write = \App\Models\ObservedWrite::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'project_id' => '00000000-0000-4000-8000-000000000002',
            'kind' => \App\Models\ObservedWrite::KIND_BOOKING,
            'status' => \App\Models\ObservedWrite::STATUS_PENDING,
            'dedupe_key' => 'booking:lead-page:1',
            'payload' => ['start_at' => '2026-11-14T17:00:00+00:00'],
            'evidence' => ['message' => 'Pode ser sábado'],
        ]);

        $user = \App\Models\User::create([
            'name' => 'Artist User',
            'email' => 'observe-review-'.Str::random(6).'@vaif.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => \App\Models\User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($user)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Para confirmar')
            ->assertSee('Agendamento')
            ->assertSee('Pode ser sábado')
            ->assertSee(route('observed-writes.decide', $write), false);
    }
}
