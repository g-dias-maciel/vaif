<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppTurn;
use App\Models\Artist;
use App\Services\Agent\AgentStore;
use App\Services\Agent\Eval\FixtureAgentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WahaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FixtureAgentStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.webhook_token' => 'waha-secret', 'agent.queue' => 'agent']);

        $this->store = new FixtureAgentStore([]);
        $this->app->instance(AgentStore::class, $this->store);
    }

    private function artist(string $status = 'live'): Artist
    {
        return Artist::create([
            'name' => 'Artist Agent',
            'status' => $status,
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'agent-artist',
        ]);
    }

    private function sendWebhook(array $body, string $token = 'waha-secret')
    {
        return $this->withHeader('X-VAIF-Webhook-Token', $token)
            ->postJson('/api/webhooks/waha', $body);
    }

    private function message(array $payload = []): array
    {
        return [
            'event' => 'message',
            'session' => 'agent-artist',
            'payload' => array_merge([
                'id' => 'ABC123',
                'from' => '5511900000001@c.us',
                'fromMe' => false,
                'body' => 'Oi, quero uma tattoo',
                'type' => 'chat',
                '_data' => ['key' => ['remoteJid' => '5511900000001@c.us'], 'pushName' => 'Ana'],
            ], $payload),
        ];
    }

    public function test_the_agent_pipeline_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(
            \App\Services\Agent\TurnProcessor::class,
            $this->app->make(\App\Services\Agent\TurnProcessor::class)
        );
    }

    public function test_it_rejects_a_wrong_token(): void
    {
        $this->sendWebhook($this->message(), 'wrong')->assertUnauthorized();
        $this->assertSame([], $this->store->accumulated);
    }

    public function test_an_unknown_session_is_logged_as_a_drop(): void
    {
        $body = $this->message();
        $body['session'] = 'ghost';

        $this->sendWebhook($body)->assertOk();

        $this->assertSame(['session_not_found'], $this->store->drops);
    }

    public function test_a_suspended_artist_is_logged_and_not_queued(): void
    {
        Queue::fake();
        $this->artist('suspended');

        $this->sendWebhook($this->message())->assertOk();

        $this->assertSame(['artist_suspended'], $this->store->drops);
        Queue::assertNothingPushed();
    }

    public function test_a_message_is_buffered_and_the_grounded_turn_is_queued(): void
    {
        Queue::fake();
        $artist = $this->artist();

        $this->sendWebhook($this->message())->assertOk()->assertJson(['queued' => true]);

        $this->assertCount(1, $this->store->accumulated);
        $this->assertSame('Oi, quero uma tattoo', $this->store->accumulated[0]['text']);

        Queue::assertPushed(ProcessWhatsAppTurn::class, function (ProcessWhatsAppTurn $job) use ($artist) {
            return $job->artistId === $artist->id
                && $job->chatId === 'agent-artist:5511900000001@c.us'
                && $job->phone === '5511900000001';
        });
    }

    public function test_a_lid_number_uses_the_real_phone_from_remote_jid_alt(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message([
            'from' => '29299888777@lid',
            '_data' => [
                'key' => [
                    'remoteJid' => '29299888777@lid',
                    'remoteJidAlt' => '5511911112222@s.whatsapp.net',
                ],
            ],
        ]))->assertOk();

        Queue::assertPushed(ProcessWhatsAppTurn::class, fn (ProcessWhatsAppTurn $job) => $job->phone === '5511911112222');
    }

    public function test_group_messages_are_ignored(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message(['from' => '120363000000000000@g.us']))->assertOk();

        $this->assertSame([], $this->store->accumulated);
        Queue::assertNothingPushed();
    }

    public function test_the_artist_replying_takes_over_the_chat(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message(['fromMe' => true, 'body' => 'Oi, aqui é a Eva']))
            ->assertOk()
            ->assertJson(['from_me' => true]);

        $this->assertSame(['agent-artist:5511900000001@c.us'], $this->store->controls);
        $this->assertSame('Oi, aqui é a Eva', $this->store->artistMessages[0]['text']);
        Queue::assertNothingPushed();
    }

    public function test_presence_update_records_typing(): void
    {
        $this->artist();

        $this->sendWebhook([
            'event' => 'presence.update',
            'session' => 'agent-artist',
            'payload' => [
                'id' => '5511900000001@c.us',
                'presences' => [['lastKnownPresence' => 'typing']],
            ],
        ])->assertOk();

        $this->assertSame([
            ['chat_id' => 'agent-artist:5511900000001@c.us', 'state' => 'typing'],
        ], $this->store->typingStates);
    }

    public function test_media_without_a_body_still_reaches_the_agent(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message([
            'body' => '',
            'type' => 'image',
            'hasMedia' => true,
            'media' => ['url' => 'https://waha.test/media/1.jpg', 'mimetype' => 'image/jpeg'],
        ]))->assertOk();

        $text = $this->store->accumulated[0]['text'];

        $this->assertStringContainsString('[FOTO RECEBIDA]', $text);
        $this->assertStringContainsString('[[REF_FOTO:https://waha.test/media/1.jpg]]', $text);
    }
}
