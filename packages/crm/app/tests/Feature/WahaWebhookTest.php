<?php

namespace Tests\Feature;

use App\Jobs\CompareDraft;
use App\Jobs\ProcessWhatsAppTurn;
use App\Jobs\TranscribeArtistAudio;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Services\Agent\AgentStore;
use App\Services\Agent\Eval\FixtureAgentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    private function artist(string $status = 'live', string $mode = 'auto'): Artist
    {
        $artist = Artist::create([
            'name' => 'Artist Agent',
            'status' => $status,
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'agent-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Agent',
            'reply_mode' => $mode,
        ]);

        return $artist;
    }

    private function sendWebhook(array $body, string $token = 'waha-secret')
    {
        return $this->withHeader('X-VAIF-Webhook-Token', $token)
            ->postJson('/api/webhooks/waha', $body);
    }

    private function message(array $payload = [], string $event = 'message'): array
    {
        return [
            'event' => $event,
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
        $this->assertInstanceOf(
            \App\Services\Agent\DraftProcessor::class,
            $this->app->make(\App\Services\Agent\DraftProcessor::class)
        );
        $this->assertInstanceOf(
            \App\Services\Agent\DraftComparator::class,
            $this->app->make(\App\Services\Agent\DraftComparator::class)
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

    public function test_a_suspended_artist_in_draft_mode_still_gets_a_draft(): void
    {
        Queue::fake();
        $artist = $this->artist('suspended', 'draft');

        $this->sendWebhook($this->message())->assertOk()->assertJson(['queued' => true]);

        $this->assertSame([], $this->store->drops);
        $this->assertCount(1, $this->store->accumulated);
        Queue::assertPushed(ProcessWhatsAppTurn::class);
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

    public function test_a_blocked_number_is_ignored_in_both_directions(): void
    {
        Queue::fake();
        $artist = $this->artist('live', 'draft');
        $artist->settings->update(['blocked_numbers' => ['5511900000001']]);

        $this->sendWebhook($this->message())->assertOk()->assertJson(['ignored' => 'blocked']);
        $this->assertSame([], $this->store->accumulated);

        $this->sendWebhook($this->message(['fromMe' => true, 'body' => 'Oi, mãe']))
            ->assertOk()
            ->assertJson(['ignored' => 'blocked']);

        $this->assertSame([], $this->store->controls);
        $this->assertSame([], $this->store->artistMessages);
        Queue::assertNothingPushed();
    }

    public function test_group_messages_are_ignored(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message(['from' => '120363000000000000@g.us']))->assertOk();

        $this->assertSame([], $this->store->accumulated);
        Queue::assertNothingPushed();
    }

    public function test_message_any_is_accepted_for_inbound_messages(): void
    {
        Queue::fake();
        $this->artist();

        $this->sendWebhook($this->message([], 'message.any'))->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(ProcessWhatsAppTurn::class);
    }

    public function test_a_from_me_message_from_the_phone_takes_over_using_the_chat_jid(): void
    {
        Queue::fake();
        $this->artist('live', 'draft');

        $this->sendWebhook([
            'event' => 'message.any',
            'session' => 'agent-artist',
            'payload' => [
                'fromMe' => true,
                'source' => 'app',
                'from' => '553599968249@c.us',
                'to' => '5511900000001@c.us',
                'body' => 'Oi, aqui é a Eva',
                '_data' => [
                    'key' => [
                        'remoteJid' => '5511900000001@c.us',
                        'remoteJidAlt' => '5511900000001@s.whatsapp.net',
                        'fromMe' => true,
                    ],
                ],
            ],
        ])->assertOk()->assertJson(['from_me' => true]);

        $this->assertSame(['agent-artist:5511900000001@c.us'], $this->store->controls);
        $this->assertSame('agent-artist:5511900000001@c.us', $this->store->artistMessages[0]['chat_id']);
        $this->assertSame('Oi, aqui é a Eva', $this->store->artistMessages[0]['text']);
    }

    public function test_an_artist_audio_is_queued_for_transcription(): void
    {
        Queue::fake();
        $this->artist('live', 'draft');

        $this->sendWebhook([
            'event' => 'message.any',
            'session' => 'agent-artist',
            'payload' => [
                'id' => 'true_5511900000001@lid_AUDIO1',
                'fromMe' => true,
                'source' => 'app',
                'from' => '553599968249@c.us',
                'to' => '5511900000001@c.us',
                'type' => 'ptt',
                'hasMedia' => true,
                'media' => [
                    'url' => 'https://waha.test/media/artist-audio.ogg',
                    'mimetype' => 'audio/ogg; codecs=opus',
                ],
                '_data' => [
                    'key' => [
                        'remoteJid' => '5511900000001@c.us',
                        'remoteJidAlt' => '5511900000001@s.whatsapp.net',
                        'fromMe' => true,
                    ],
                    'message' => ['pttMessage' => []],
                ],
            ],
        ])->assertOk()->assertJson(['from_me' => true]);

        $this->assertSame('[ÁUDIO ENVIADO]', $this->store->artistMessages[0]['text']);

        Queue::assertPushed(TranscribeArtistAudio::class, function (TranscribeArtistAudio $job) {
            return $job->waMessageId === 'true_5511900000001@lid_AUDIO1'
                && $job->mediaUrl === 'https://waha.test/media/artist-audio.ogg'
                && $job->mime === 'audio/ogg; codecs=opus';
        });
    }

    public function test_a_numeric_from_me_flag_is_treated_as_the_artist(): void
    {
        Queue::fake();
        $this->artist('live', 'draft');

        $this->sendWebhook([
            'event' => 'message.any',
            'session' => 'agent-artist',
            'payload' => [
                'fromMe' => 1,
                'source' => 'app',
                'from' => '553599968249@c.us',
                'to' => '5511900000001@c.us',
                'body' => 'Oi, aqui é a Eva',
                '_data' => ['key' => ['remoteJid' => '5511900000001@c.us', 'fromMe' => 1]],
            ],
        ])->assertOk()->assertJson(['from_me' => true]);

        $this->assertSame(['agent-artist:5511900000001@c.us'], $this->store->controls);
        $this->assertSame('Oi, aqui é a Eva', $this->store->artistMessages[0]['text']);
    }

    public function test_a_from_me_message_sent_by_the_api_is_not_an_artist_takeover(): void
    {
        Queue::fake();
        $this->artist('live', 'draft');

        $this->sendWebhook([
            'event' => 'message.any',
            'session' => 'agent-artist',
            'payload' => [
                'fromMe' => true,
                'source' => 'api',
                'from' => '553599968249@c.us',
                'to' => '5511900000001@c.us',
                'body' => 'Resposta automática',
                '_data' => ['key' => ['remoteJid' => '5511900000001@c.us', 'fromMe' => true]],
            ],
        ])->assertOk()->assertJson(['ignored' => 'api_message']);

        $this->assertSame([], $this->store->controls);
        $this->assertSame([], $this->store->artistMessages);
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

    public function test_an_artist_reply_is_paired_with_the_pending_draft(): void
    {
        Queue::fake();
        $artist = $this->artist('live', 'draft');

        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'chat_id' => 'agent-artist:5511900000001@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600,00.',
            'status' => AgentDraft::STATUS_PENDING,
        ]);

        $this->sendWebhook($this->message(['fromMe' => true, 'body' => 'Oi, aqui é a Eva']))->assertOk();

        $draft->refresh();
        $this->assertSame('Oi, aqui é a Eva', $draft->artist_reply);
        $this->assertSame(AgentDraft::STATUS_REPLIED, $draft->status);
        $this->assertNotNull($draft->response_seconds);

        Queue::assertPushed(CompareDraft::class, fn (CompareDraft $job) => $job->draftId === $draft->id);
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
