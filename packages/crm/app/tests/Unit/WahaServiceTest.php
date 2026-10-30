<?php

namespace Tests\Unit;

use App\Models\Artist;
use App\Services\WahaService;
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
                && $request['config']['webhooks'][0]['customHeaders'][0]['value'] === 'shared-secret';
        });
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
