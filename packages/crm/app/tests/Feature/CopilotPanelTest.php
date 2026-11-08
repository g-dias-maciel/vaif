<?php

namespace Tests\Feature;

use App\Jobs\GenerateCopilotReport;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\CopilotReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CopilotPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        config([
            'copilot.enabled' => true,
            'copilot.recipients' => [],
        ]);
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

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Panel Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'panel-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Panel Artist',
            'reply_mode' => 'draft',
        ]);

        return $artist;
    }

    public function test_staff_can_see_the_copilot_index(): void
    {
        $this->actingAs($this->staff())
            ->get(route('copilot.index'))
            ->assertOk()
            ->assertSee('Copiloto')
            ->assertSee('Ops diário')
            ->assertSee('Calibração semanal');
    }

    public function test_artists_cannot_see_the_copilot(): void
    {
        $artist = $this->artist();
        $user = User::create([
            'name' => 'Artist Login',
            'email' => 'artist-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($user)->get(route('copilot.index'))->assertForbidden();
    }

    public function test_generate_queues_a_report(): void
    {
        Queue::fake();

        $this->actingAs($this->staff())
            ->post(route('copilot.generate'), ['kind' => 'ops'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $report = CopilotReport::where('kind', CopilotReport::KIND_OPS)->firstOrFail();
        $this->assertSame(CopilotReport::STATUS_QUEUED, $report->status);

        Queue::assertPushed(GenerateCopilotReport::class, function (GenerateCopilotReport $job) use ($report) {
            return $job->kind === CopilotReport::KIND_OPS
                && $job->periodKey === $report->period_key;
        });
    }

    public function test_show_renders_the_narrative_and_findings(): void
    {
        $report = CopilotReport::create([
            'kind' => CopilotReport::KIND_OPS,
            'period_key' => 'ops:'.now()->toDateString(),
            'status' => CopilotReport::STATUS_READY,
            'facts' => [
                'window_hours' => 24,
                'queue' => ['pending' => 2, 'oldest_age_minutes' => 12],
                'failed_jobs' => ['recent' => 0, 'samples' => []],
                'webhooks' => [],
                'handoffs' => ['open' => 0, 'oldest' => []],
                'stuck_drafts' => ['pending_over_threshold' => 0, 'samples' => []],
                'ingest_drops' => ['total' => 0, 'by_reason' => []],
                'calibration' => [],
                'artists' => [],
            ],
            'narrative' => 'Resumo do relatório de operação.',
            'findings' => [[
                'severity' => 'warning',
                'title' => 'Fila atrasada',
                'body' => 'Dois jobs pendentes.',
                'evidence' => ['2 jobs'],
                'suggested_action' => 'Verificar o worker.',
            ]],
            'generated_at' => now(),
        ]);

        $this->actingAs($this->staff())
            ->get(route('copilot.show', $report))
            ->assertOk()
            ->assertSee('Resumo do relatório de operação.')
            ->assertSee('Fila atrasada')
            ->assertSee('Verificar o worker.');
    }
}
