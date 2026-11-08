<?php

namespace Tests\Feature;

use App\Mail\ArtistInvite;
use App\Models\Artist;
use App\Models\OnboardingTask;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SalesHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $this->prospect = Prospect::create([
            'name' => 'Artista Handoff',
            'whatsapp_e164' => '+5511999990001',
            'whatsapp_raw' => '(11) 99999-0001',
            'instagram' => 'artistahandoff',
            'source' => 'calculadora',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'proposta',
            'revenue_monthly_cents' => 1_500_000,
            'ticket_cents' => 150_000,
            'sessions_monthly' => 10,
            'admin_hours_day' => 2,
            'owner_id' => $this->sales->id,
        ]);
    }

    public function test_retainer_handoff_creates_artist_contract_and_tasks(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), $this->retainerPayload())
            ->assertRedirect(route('sales.prospects.show', $this->prospect));

        $this->prospect->refresh();

        $this->assertSame('ganho', $this->prospect->stage_key);
        $this->assertNotNull($this->prospect->artist_id);
        $this->assertNotNull($this->prospect->handoff_at);

        $artist = Artist::findOrFail($this->prospect->artist_id);

        $this->assertSame('onboarding', $artist->status);
        $this->assertSame('artista-handoff', $artist->wa_session_slug);

        $this->assertDatabaseHas('artist_settings', [
            'artist_id' => $artist->id,
            'display_name' => 'Artista Handoff',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'artist@example.com',
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $contract = $this->prospect->contracts()->firstOrFail();

        $this->assertSame('semestral', $contract->plan);
        $this->assertSame(180_000, $contract->monthly_value_cents);
        $this->assertSame(1_080_000, $contract->total_value_cents);
        $this->assertSame($artist->id, $contract->artist_id);
        $this->assertNotNull($contract->end_date);

        $this->assertSame(
            7,
            OnboardingTask::where('artist_id', $artist->id)->count(),
        );

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $this->prospect->id,
            'type' => 'handoff',
        ]);

        Mail::assertSent(ArtistInvite::class);
    }

    public function test_members_area_handoff_creates_contract_and_tasks_without_artist(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), [
                'plan' => 'members_area',
                'start_date' => now()->toDateString(),
            ])
            ->assertRedirect(route('sales.prospects.show', $this->prospect));

        $this->prospect->refresh();

        $this->assertSame('ganho', $this->prospect->stage_key);
        $this->assertNull($this->prospect->artist_id);
        $this->assertNotNull($this->prospect->handoff_at);
        $this->assertSame(0, Artist::count());

        $contract = $this->prospect->contracts()->firstOrFail();

        $this->assertSame('members_area', $contract->plan);
        $this->assertSame(0, $contract->monthly_value_cents);
        $this->assertSame(69_700, $contract->total_value_cents);
        $this->assertNull($contract->artist_id);

        $this->assertSame(
            3,
            $this->prospect->activities()->where('type', 'task')->count(),
        );

        Mail::assertNothingSent();
    }

    public function test_handoff_is_blocked_when_the_prospect_was_already_converted(): void
    {
        $this->prospect->update(['handoff_at' => now()]);

        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), $this->retainerPayload())
            ->assertSessionHasErrors('handoff');

        $this->assertSame('proposta', $this->prospect->fresh()->stage_key);
        $this->assertSame(0, Artist::count());
    }

    public function test_sales_cannot_handoff_another_sellers_prospect(): void
    {
        $other = User::create([
            'name' => 'Other Sales',
            'email' => 'other.sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $this->prospect->update(['owner_id' => $other->id]);

        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), $this->retainerPayload())
            ->assertForbidden();
    }

    public function test_retainer_handoff_requires_an_artist_email(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), $this->retainerPayload([
                'artist_email' => null,
            ]))
            ->assertSessionHasErrors('artist_email');

        $this->assertSame(0, Artist::count());
        $this->assertSame('proposta', $this->prospect->fresh()->stage_key);
    }

    public function test_handoff_rolls_back_when_the_artist_email_is_already_taken(): void
    {
        User::create([
            'name' => 'Existing Login',
            'email' => 'artist@example.com',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($this->sales)
            ->post(route('sales.prospects.handoff', $this->prospect), $this->retainerPayload())
            ->assertSessionHasErrors('artist_email');

        $this->assertSame(0, Artist::count());
        $this->assertSame('proposta', $this->prospect->fresh()->stage_key);
        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function retainerPayload(array $overrides = []): array
    {
        return array_merge([
            'plan' => 'semestral',
            'monthly_value' => 1800,
            'start_date' => now()->toDateString(),
            'billing_day' => 10,
            'artist_email' => 'artist@example.com',
            'whatsapp_number' => '(11) 99999-0001',
            'instagram_handle' => 'artistahandoff',
            'timezone' => 'America/Sao_Paulo',
        ], $overrides);
    }
}
