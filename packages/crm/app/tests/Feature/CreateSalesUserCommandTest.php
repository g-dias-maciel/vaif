<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSalesUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_sales_login(): void
    {
        $this->artisan('crm:make-sales', [
            'email' => 'Vendas@VAIF.com.br',
            '--name' => 'Vendas',
            '--password' => 'super-secret-1',
        ])->assertExitCode(0);

        $user = User::where('email', 'vendas@vaif.com.br')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isSales());
        $this->assertNull($user->artist_id);
        $this->assertSame('Vendas', $user->name);
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_it_derives_a_display_name_from_the_email(): void
    {
        $this->artisan('crm:make-sales', [
            'email' => 'vendas.vaif@vaif.com.br',
            '--password' => 'super-secret-1',
        ])->assertExitCode(0);

        $this->assertSame('Vendas Vaif', User::firstOrFail()->name);
    }

    public function test_it_keeps_the_password_when_rerun_without_one(): void
    {
        User::create([
            'name' => 'Vendas',
            'email' => 'vendas@vaif.com.br',
            'password' => 'original-password-1',
            'role' => User::ROLE_SALES,
        ]);

        $this->artisan('crm:make-sales', ['email' => 'vendas@vaif.com.br'])
            ->assertExitCode(0);

        $user = User::where('email', 'vendas@vaif.com.br')->firstOrFail();

        $this->assertTrue(Hash::check('original-password-1', $user->password));
    }

    public function test_it_refuses_to_change_an_artist_without_force(): void
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

        $this->artisan('crm:make-sales', [
            'email' => 'artist@vaif.com.br',
            '--password' => 'super-secret-1',
        ])->assertExitCode(1);

        $user = User::where('email', 'artist@vaif.com.br')->firstOrFail();
        $this->assertTrue($user->isArtist());
        $this->assertSame($artist->id, $user->artist_id);
    }

    public function test_force_changes_an_artist_to_sales_and_unlinks_the_artist(): void
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

        $this->artisan('crm:make-sales', [
            'email' => 'artist@vaif.com.br',
            '--password' => 'super-secret-1',
            '--force' => true,
        ])->assertExitCode(0);

        $user = User::where('email', 'artist@vaif.com.br')->firstOrFail();
        $this->assertTrue($user->isSales());
        $this->assertNull($user->artist_id);
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_it_refuses_to_change_a_staff_login_without_force(): void
    {
        User::create([
            'name' => 'Staff',
            'email' => 'staff@vaif.com.br',
            'password' => 'staff-password-1',
            'role' => User::ROLE_STAFF,
        ]);

        $this->artisan('crm:make-sales', [
            'email' => 'staff@vaif.com.br',
            '--password' => 'super-secret-1',
        ])->assertExitCode(1);

        $this->assertTrue(User::where('email', 'staff@vaif.com.br')->firstOrFail()->isStaff());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('crm:make-sales', [
            'email' => 'not-an-email',
            '--password' => 'super-secret-1',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('crm:make-sales', [
            'email' => 'vendas@vaif.com.br',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}
