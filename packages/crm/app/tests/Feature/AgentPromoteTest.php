<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPromoteTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $mode = 'draft'): Artist
    {
        $artist = Artist::create([
            'name' => 'Promote Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'promote-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'reply_mode' => $mode,
        ]);

        return $artist;
    }

    public function test_promotion_to_auto_is_blocked_until_the_gates_pass(): void
    {
        $artist = $this->artist();

        $this->artisan('agent:promote', ['artist' => $artist->id, '--to' => 'auto'])
            ->assertExitCode(1);

        $this->assertSame('draft', $artist->fresh()->settings->reply_mode);
    }

    public function test_force_promotes_to_auto(): void
    {
        $artist = $this->artist();

        $this->artisan('agent:promote', ['artist' => $artist->id, '--to' => 'auto', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('auto', $artist->fresh()->settings->reply_mode);
    }

    public function test_demoting_to_draft_is_always_allowed(): void
    {
        $artist = $this->artist('auto');

        $this->artisan('agent:promote', ['artist' => $artist->id, '--to' => 'draft'])
            ->assertExitCode(0);

        $this->assertSame('draft', $artist->fresh()->settings->reply_mode);
    }
}
