<?php

namespace Tests\Unit;

use App\Models\Artist;
use App\Services\WahaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaServiceTest extends TestCase
{
    private function artist(): Artist
    {
        return new Artist(['wa_session_slug' => 'default']);
    }

    public function test_status_reports_connected_when_working()
    {
        Http::fake([
            'waha.test/api/sessions/default' => Http::response([
                'status' => 'WORKING',
                'me' => ['pushName' => 'Bruno'],
            ], 200),
        ]);
        config(['transport.waha_url' => 'https://waha.test']);

        $status = WahaService::make()->status($this->artist());

        $this->assertNotNull($status);
        $this->assertTrue($status['connected']);
        $this->assertSame('Bruno', $status['me']);
    }

    public function test_status_returns_null_on_http_error()
    {
        Http::fake(['waha.test/*' => Http::response('', 500)]);
        config(['transport.waha_url' => 'https://waha.test']);

        $this->assertNull(WahaService::make()->status($this->artist()));
    }

    public function test_chats_accepts_a_bare_list_and_a_wrapped_payload()
    {
        config(['transport.waha_url' => 'https://waha.test']);

        Http::fake([
            'waha.test/api/default/chats*' => Http::sequence()
                ->push([['id' => '5511@s.whatsapp.net']], 200)
                ->push(['chats' => [['id' => '5522@s.whatsapp.net']]], 200),
        ]);

        $service = WahaService::make();

        $this->assertSame('5511@s.whatsapp.net', $service->chats($this->artist())[0]['id']);
        $this->assertSame('5522@s.whatsapp.net', $service->chats($this->artist())[0]['id']);
    }

    public function test_qr_returns_base64_payload()
    {
        Http::fake([
            'waha.test/api/default/auth/qr*' => Http::response(['qrcode' => 'base64png'], 200),
        ]);
        config(['transport.waha_url' => 'https://waha.test']);

        $this->assertSame('base64png', WahaService::make()->qr($this->artist()));
    }

    public function test_create_posts_session_with_webhook()
    {
        Http::fake();
        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_webhook_url' => 'https://n8n.test/webhook/waha-webhook',
            'transport.waha_webhook_token' => 'shared-secret',
        ]);

        $ok = WahaService::make()->create($this->artist());

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://waha.test/api/sessions'
                && $request['name'] === 'default'
                && $request['config']['webhooks'][0]['url'] === 'https://n8n.test/webhook/waha-webhook'
                && $request['config']['webhooks'][0]['customHeaders'][0]['name'] === 'X-VAIF-Webhook-Token'
                && $request['config']['webhooks'][0]['customHeaders'][0]['value'] === 'shared-secret'
                // New sessions must be born with the NOWEB store so the agent
                // can read the artist's saved contacts.
                && $request['config']['noweb']['store']['enabled'] === true
                && $request['config']['noweb']['store']['fullSync'] === true;
        });
    }

    public function test_relink_deletes_then_creates_with_the_store()
    {
        Http::fake();
        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_webhook_url' => 'https://n8n.test/webhook/waha-webhook',
        ]);

        $ok = WahaService::make()->relink($this->artist());

        $this->assertTrue($ok);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://waha.test/api/sessions/default');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://waha.test/api/sessions'
            && $request['name'] === 'default'
            && $request['config']['noweb']['store']['enabled'] === true);
    }

    public function test_is_saved_contact_requires_an_address_book_name()
    {
        Http::fake([
            'waha.test/api/contacts/all*' => Http::response([
                ['id' => '5511900000000@c.us', 'phoneNumber' => '5511900000000@s.whatsapp.net', 'name' => 'Salvo'],
                ['id' => '5511800000000@c.us', 'phoneNumber' => '5511800000000@s.whatsapp.net', 'pushname' => 'Nao Salvo'],
            ], 200),
        ]);
        config(['transport.waha_url' => 'https://waha.test']);
        Cache::flush();

        $service = WahaService::make();
        $artist = $this->artist();

        $this->assertTrue($service->isSavedContact($artist, '+5511900000000'));
        $this->assertFalse($service->isSavedContact($artist, '+5511800000000'));
        $this->assertFalse($service->isSavedContact($artist, '+5511700000000'));
    }

    public function test_is_saved_contact_returns_null_when_waha_fails()
    {
        Http::fake(['waha.test/*' => Http::response('', 500)]);
        config(['transport.waha_url' => 'https://waha.test']);
        Cache::flush();

        $this->assertNull(WahaService::make()->isSavedContact($this->artist(), '+5511900000000'));
    }

    public function test_send_text_posts_to_the_session()
    {
        Http::fake();
        config(['transport.waha_url' => 'https://waha.test']);

        $ok = WahaService::make()->sendText($this->artist(), '5511999999999@c.us', 'Oi');

        $this->assertTrue($ok);

        Http::assertSent(fn ($request) => $request->url() === 'https://waha.test/api/sendText'
            && $request['session'] === 'default'
            && $request['chatId'] === '5511999999999@c.us'
            && $request['text'] === 'Oi');
    }

    public function test_restart_posts_to_restart_endpoint()
    {
        Http::fake();
        config(['transport.waha_url' => 'https://waha.test']);

        $ok = WahaService::make()->restart($this->artist());

        $this->assertTrue($ok);

        Http::assertSent(fn ($request) => $request->url() === 'https://waha.test/api/sessions/default/restart');
    }
}
