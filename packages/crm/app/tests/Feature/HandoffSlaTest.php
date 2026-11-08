<?php

namespace Tests\Feature;

use App\Mail\ArtistAttention;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class HandoffSlaTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $artist = Artist::create([
            'name' => 'Adriano',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'sla-test',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Adriano',
        ]);

        $user = User::create([
            'name' => 'Adriano',
            'email' => 'adriano@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000300',
        ]);

        $project = Project::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_artista',
            'handoff_reason' => 'cover_up',
            'handoff_at' => now(),
        ]);

        return [$artist, $user, $lead, $project];
    }

    public function test_handoff_notified_at_is_written_in_utc(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::create(2026, 11, 6, 22, 2, 0, 'America/Sao_Paulo'));

        [$artist, $user, $lead, $project] = $this->fixture();

        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);

        $fresh = $project->fresh();

        $this->assertNotNull($fresh->handoff_notified_at);

        // 22:02 in São Paulo is 01:02 UTC the next day. A regression to
        // `now()` would store 22:02 and fail here.
        $this->assertSame(
            '2026-11-07 01:02:00',
            $fresh->handoff_notified_at->format('Y-m-d H:i:s')
        );

        $this->assertTrue(
            $fresh->handoff_notified_at->gte($fresh->handoff_at),
            'handoff_notified_at must never precede handoff_at'
        );
    }

    public function test_it_reminds_after_45_minutes_and_escalates_after_4_hours(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::create(2026, 11, 6, 12, 0, 0, 'America/Sao_Paulo'));

        [$artist, $user, $lead, $project] = $this->fixture();

        // The first e-mail went out 50 minutes ago.
        // SQLite stores naive datetimes; use local wall time so the cast reads
        // back the same instant the test means. Production stores a real
        // timestamptz and the comparison is timezone-correct.
        DB::table('projects')->where('id', $project->id)->update([
            'handoff_at' => now()->subMinutes(50),
            'handoff_notified_at' => now()->subMinutes(50),
            'handoff_reminded_at' => null,
            'handoff_escalated_at' => null,
        ]);

        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);

        Mail::assertSent(ArtistAttention::class, function (ArtistAttention $mail) {
            return $mail->stage === 'reminder';
        });

        $this->assertNotNull($project->fresh()->handoff_reminded_at);
        $this->assertNull($project->fresh()->handoff_escalated_at);

        // The same reminder must not repeat.
        Mail::fake();
        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);
        Mail::assertNothingSent();

        // Four and a half hours after the handoff, escalate.
        Carbon::setTestNow(now()->addHours(4));

        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);

        Mail::assertSent(ArtistAttention::class, function (ArtistAttention $mail) {
            return $mail->stage === 'escalation';
        });

        $this->assertNotNull($project->fresh()->handoff_escalated_at);
    }

    public function test_a_resolved_handoff_is_never_notified(): void
    {
        Mail::fake();

        [$artist, $user, $lead, $project] = $this->fixture();

        DB::table('projects')->where('id', $project->id)->update([
            'handoff_resolved_at' => now()->utc(),
        ]);

        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_the_reminder_email_renders_the_sla_banner(): void
    {
        [$artist] = $this->fixture();

        $html = (new ArtistAttention(
            $artist,
            [['name' => 'Cliente', 'phone' => '+5511900000300', 'reason' => 'Cover-up', 'url' => 'https://crm.vaif.com.br/leads/1', 'age' => '50 min']],
            [],
            [],
            'reminder',
        ))->render();

        $this->assertStringContainsString('45 minutos sem resposta', $html);
        $this->assertStringContainsString('esperando há 50 min', $html);
    }
}
