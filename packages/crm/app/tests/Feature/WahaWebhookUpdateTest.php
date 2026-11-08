<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Services\WahaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaWebhookUpdateTest extends TestCase
{
    private function artist(): Artist
    {
        return new Artist([
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Artist',
            'status' => 'live',
            'wa_session_slug' => 'agent-artist',
        ]);
    }

    public function test_it_updates_the_webhook_without_losing_other_session_config(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/agent-artist' => Http::sequence()
                ->push([
                    'name' => 'agent-artist',
                    'status' => 'WORKING',
                    'config' => [
                        'metadata' => ['source' => 'crm'],
                        'noweb' => ['store' => ['enabled' => true, 'fullSync' => true]],
                        'webhooks' => [['url' => 'https://n8n.test/hook', 'events' => ['message']]],
                    ],
                ])
                ->push(['name' => 'agent-artist', 'status' => 'WORKING']),
        ]);

        $ok = (new WahaService('https://waha.test', 'key'))->updateWebhook(
            $this->artist(),
            'https://crm.test/api/webhooks/waha',
            'secret',
        );

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $body = $request->data();

            return $body['name'] === 'agent-artist'
                && $body['config']['metadata'] === ['source' => 'crm']
                && $body['config']['noweb']['store']['enabled'] === true
                && $body['config']['webhooks'][0]['url'] === 'https://crm.test/api/webhooks/waha'
                && $body['config']['webhooks'][0]['customHeaders'][0]['name'] === 'X-VAIF-Webhook-Token'
                && $body['config']['webhooks'][0]['customHeaders'][0]['value'] === 'secret';
        });
    }

    public function test_it_does_not_touch_the_session_when_reading_the_config_fails(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/agent-artist' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $ok = (new WahaService('https://waha.test', 'key'))->updateWebhook($this->artist());

        $this->assertFalse($ok);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }
}
