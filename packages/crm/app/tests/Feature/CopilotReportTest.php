<?php

namespace Tests\Feature;

use App\Mail\CopilotReportMail;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\CopilotReport;
use App\Models\User;
use App\Services\Agent\AiClient;
use App\Services\Agent\Eval\ScriptedAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CopilotReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'key',
            'transport.waha_webhook_url' => 'https://crm.test/api/webhooks/waha',
            'transport.n8n_webhook_url' => 'https://n8n.test/webhook/waha-webhook',
            'copilot.enabled' => true,
            'copilot.mail' => true,
            'copilot.recipients' => [],
            'copilot.model' => 'test-model',
        ]);

        Http::fake([
            'https://waha.test/api/sessions/*' => Http::response([
                'status' => 'WORKING',
                'config' => ['webhooks' => [[
                    'url' => 'https://crm.test/api/webhooks/waha',
                    'events' => ['message'],
                ]]],
            ], 200),
        ]);
    }

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Report Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'report-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Report Artist',
            'reply_mode' => 'draft',
        ]);

        return $artist;
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    private function bindAnalyst(): void
    {
        $this->app->instance(AiClient::class, new ScriptedAiClient([
            'agent' => [
                'summary' => 'Resumo do copiloto.',
                'findings' => [[
                    'severity' => 'warning',
                    'title' => 'Fila com job atrasado',
                    'body' => 'Há um job pendente.',
                    'evidence' => ['1 job'],
                    'suggested_action' => 'Verificar o worker.',
                ]],
            ],
        ]));
    }

    public function test_the_command_generates_a_report_without_mail(): void
    {
        $this->artist();
        $this->bindAnalyst();

        $this->artisan('copilot:report', ['kind' => 'ops', '--no-mail' => true])
            ->assertExitCode(0);

        $report = CopilotReport::where('kind', CopilotReport::KIND_OPS)->firstOrFail();

        $this->assertSame(CopilotReport::STATUS_READY, $report->status);
        $this->assertSame('Resumo do copiloto.', $report->narrative);
        $this->assertCount(1, $report->findings);
        $this->assertSame('warning', $report->findings[0]['severity']);
        $this->assertNotEmpty($report->facts);
    }

    public function test_the_command_sends_the_report_to_staff(): void
    {
        $this->artist();
        $this->staff();
        $this->bindAnalyst();
        Mail::fake();

        $this->artisan('copilot:report', ['kind' => 'calibration'])
            ->assertExitCode(0);

        $report = CopilotReport::where('kind', CopilotReport::KIND_CALIBRATION)->firstOrFail();

        $this->assertNotNull($report->delivered_at);

        Mail::assertSent(CopilotReportMail::class, function (CopilotReportMail $mail) use ($report) {
            $html = $mail->render();

            return $mail->report->id === $report->id
                && str_contains($html, 'Resumo do copiloto.')
                && str_contains($html, 'Fila com job atrasado');
        });
    }
}
