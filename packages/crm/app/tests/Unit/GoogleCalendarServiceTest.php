<?php

namespace Tests\Unit;

use App\Services\Google\GoogleCalendarService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function configureServiceAccount(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL is not available.');
        }

        openssl_pkey_export($key, $privateKey);

        config(['services.google_calendar.credentials' => json_encode([
            'client_email' => 'vaif-calendar@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ])]);
    }

    public function test_it_lists_calendars_with_a_service_account_token(): void
    {
        $this->configureServiceAccount();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [[
                    'id' => 'artist@example.com',
                    'summary' => 'Agenda da Tattoo',
                    'timeZone' => 'America/Sao_Paulo',
                    'accessRole' => 'writer',
                ]],
            ]),
        ]);

        $calendars = app(GoogleCalendarService::class)->listCalendars();

        $this->assertCount(1, $calendars);
        $this->assertSame('artist@example.com', $calendars[0]['id']);
        $this->assertSame('Agenda da Tattoo', $calendars[0]['summary']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && is_string($request['assertion'])
                && substr_count($request['assertion'], '.') === 2;
        });

        Http::assertSent(fn ($request) => str_contains($request->url(), 'calendarList')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_creates_an_event_on_a_shared_calendar(): void
    {
        $this->configureServiceAccount();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
            'www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response(['id' => 'evt-1']),
        ]);

        $created = app(GoogleCalendarService::class)->createEvent('artist@example.com', [
            'summary' => 'Tatuagem — João',
            'start' => ['dateTime' => '2026-11-02T14:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
            'end' => ['dateTime' => '2026-11-02T16:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        ]);

        $this->assertSame('evt-1', $created['id']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendUpdates=none')
            && $request['summary'] === 'Tatuagem — João');
    }

    public function test_an_unconfigured_service_is_reported_as_not_configured(): void
    {
        config(['services.google_calendar.credentials' => null]);

        $service = app(GoogleCalendarService::class);

        $this->assertFalse($service->isConfigured());
        $this->assertNull($service->serviceAccountEmail());
    }
}
