<?php

namespace Tests\Feature;

use App\Services\Agent\CreditMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreditMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'agent.openrouter.key' => 'test-key',
            'agent.credits.warn_below' => 1.0,
            'agent.credits.throttle_minutes' => 60,
            'agent.credits.email' => 'admin@example.test',
        ]);
    }

    public function test_it_reads_the_openrouter_balance(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 30, 'total_usage' => 20.1957],
            ]),
        ]);

        $balance = (new CreditMonitor())->balance();

        $this->assertSame(30.0, $balance['total']);
        $this->assertSame(9.8043, $balance['remaining']);
    }

    public function test_it_alerts_once_when_the_balance_is_low(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 10, 'total_usage' => 9.5],
            ]),
        ]);

        Mail::shouldReceive('raw')->once();

        $monitor = new CreditMonitor();

        $this->assertTrue($monitor->checkAndAlert()['alerted']);
        $this->assertFalse($monitor->checkAndAlert()['alerted'], 'The alert must be throttled.');
    }

    public function test_it_does_not_alert_when_the_balance_is_healthy(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 30, 'total_usage' => 20],
            ]),
        ]);

        Mail::shouldReceive('raw')->never();

        $result = (new CreditMonitor())->checkAndAlert();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['alerted']);
    }
}
