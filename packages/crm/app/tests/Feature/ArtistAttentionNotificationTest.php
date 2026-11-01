<?php

namespace Tests\Feature;

use App\Mail\ArtistAttention;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArtistAttentionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): array
    {
        $artist = Artist::create([
            'name' => 'Gabriel',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'attention-test',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Gabriel',
        ]);

        $user = User::create([
            'name' => 'Gabriel',
            'email' => 'gabriel@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000200',
        ]);

        return [$artist, $user, $lead];
    }

    public function test_it_emails_handoffs_and_deposits_once(): void
    {
        Mail::fake();

        [$artist, $user, $lead] = $this->artist();

        $handoff = Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_artista',
            'handoff_reason' => 'lead_requested_artist',
        ]);

        $depositProject = Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_deposito',
        ]);

        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'project_id' => $depositProject->id,
            'kind' => 'deposit',
            'amount_cents' => 5000,
            'status' => 'requested',
            'method' => 'pix',
            'requested_at' => now(),
        ]);

        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);

        Mail::assertSent(ArtistAttention::class, function (ArtistAttention $mail) use ($user) {
            return $mail->hasTo($user->email)
                && count($mail->handoffs) === 1
                && count($mail->deposits) === 1;
        });

        $this->assertNotNull($handoff->fresh()->handoff_notified_at);
        $this->assertNotNull($payment->fresh()->deposit_notified_at);

        // A second run must not send the same alert again.
        Mail::fake();
        $this->artisan('crm:notify-artist-attention')->assertExitCode(0);
        Mail::assertNothingSent();
    }

    public function test_the_attention_email_renders_both_sections(): void
    {
        [$artist] = $this->artist();

        $html = (new ArtistAttention(
            $artist,
            [['name' => 'Cliente', 'phone' => '+5511900000200', 'reason' => 'Pediu o artista', 'url' => 'https://crm.vaif.com.br/leads/1']],
            [['name' => 'Cliente', 'amount' => '50,00', 'url' => 'https://crm.vaif.com.br/leads/1']],
        ))->render();

        $this->assertStringContainsString('Atendimentos aguardando você', $html);
        $this->assertStringContainsString('Sinais aguardando confirmação', $html);
    }
}
