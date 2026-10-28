<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `php artisan crm:make-staff` is the only supported way to bootstrap the first
 * staff login (the seeder's demo password must never reach production).
 */
class CreateStaffUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_staff_login(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'Owner@VAIF.com.br',
            '--name' => 'Owner',
            '--password' => 'super-secret-1',
        ])->assertExitCode(0);

        $user = User::where('email', 'owner@vaif.com.br')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isStaff());
        $this->assertNull($user->artist_id);
        $this->assertSame('Owner', $user->name);
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_it_derives_a_display_name_from_the_email(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'g.dias.maciel@vaif.com.br',
            '--password' => 'super-secret-1',
        ])->assertExitCode(0);

        $this->assertSame('G Dias Maciel', User::firstOrFail()->name);
    }

    public function test_it_keeps_the_password_when_rerun_without_one(): void
    {
        User::create([
            'name' => 'Owner',
            'email' => 'owner@vaif.com.br',
            'password' => 'original-password-1',
            'role' => User::ROLE_STAFF,
        ]);

        $this->artisan('crm:make-staff', ['email' => 'owner@vaif.com.br'])
            ->assertExitCode(0);

        $user = User::where('email', 'owner@vaif.com.br')->firstOrFail();

        $this->assertTrue(Hash::check('original-password-1', $user->password));
    }

    public function test_it_prompts_for_a_password_when_creating_interactively(): void
    {
        $this->artisan('crm:make-staff', ['email' => 'owner@vaif.com.br'])
            ->expectsQuestion('Password', 'super-secret-1')
            ->expectsQuestion('Confirm password', 'super-secret-1')
            ->assertExitCode(0);

        $user = User::where('email', 'owner@vaif.com.br')->firstOrFail();
        $this->assertTrue($user->isStaff());
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_it_aborts_when_the_confirmation_does_not_match(): void
    {
        $this->artisan('crm:make-staff', ['email' => 'owner@vaif.com.br'])
            ->expectsQuestion('Password', 'super-secret-1')
            ->expectsQuestion('Confirm password', 'super-secret-2')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_requires_a_password_for_a_new_login_when_non_interactive(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'owner@vaif.com.br',
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'owner@vaif.com.br',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_an_already_hashed_password(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'owner@vaif.com.br',
            '--password' => Hash::make('super-secret-1'),
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('crm:make-staff', [
            'email' => 'not-an-email',
            '--password' => 'super-secret-1',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_to_promote_an_artist_without_force(): void
    {
        $artist = Artist::create([
            'id' => 'a0000000-0000-0000-0000-000000000001',
            'name' => 'Artist',
            'status' => 'live',
            'wa_session_slug' => 'artist',
        ]);

        User::create([
            'name' => 'Artist',
            'email' => 'artist@vaif.com.br',
            'password' => 'artist-password-1',
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->artisan('crm:make-staff', [
            'email' => 'artist@vaif.com.br',
            '--password' => 'super-secret-1',
        ])->assertExitCode(1);

        $user = User::where('email', 'artist@vaif.com.br')->firstOrFail();
        $this->assertTrue($user->isArtist());
        $this->assertSame($artist->id, $user->artist_id);
    }

    public function test_force_promotes_an_artist_and_unlinks_the_artist(): void
    {
        $artist = Artist::create([
            'id' => 'a0000000-0000-0000-0000-000000000001',
            'name' => 'Artist',
            'status' => 'live',
            'wa_session_slug' => 'artist',
        ]);

        User::create([
            'name' => 'Artist',
            'email' => 'artist@vaif.com.br',
            'password' => 'artist-password-1',
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->artisan('crm:make-staff', [
            'email' => 'artist@vaif.com.br',
            '--password' => 'super-secret-1',
            '--force' => true,
        ])->assertExitCode(0);

        $user = User::where('email', 'artist@vaif.com.br')->firstOrFail();
        $this->assertTrue($user->isStaff());
        $this->assertNull($user->artist_id);
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }
}
