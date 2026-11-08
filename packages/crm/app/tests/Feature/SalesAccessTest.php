<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_open_the_sales_pages(): void
    {
        $sales = $this->userWithRole(User::ROLE_SALES, 'sales@vaif.test');

        foreach (['/sales', '/sales/pipeline', '/sales/backlog', '/sales/campaigns'] as $path) {
            $this->actingAs($sales)->get($path)->assertOk();
        }
    }

    public function test_sales_cannot_open_artist_pages(): void
    {
        $sales = $this->userWithRole(User::ROLE_SALES, 'sales@vaif.test');

        $this->actingAs($sales)->get('/artists')->assertForbidden();
        $this->actingAs($sales)->get('/promotions')->assertForbidden();
        $this->actingAs($sales)->get('/calibration')->assertForbidden();
    }

    public function test_staff_can_open_the_sales_pages(): void
    {
        $staff = $this->userWithRole(User::ROLE_STAFF, 'staff@vaif.test');

        $this->actingAs($staff)->get('/sales')->assertOk();
        $this->actingAs($staff)->get('/sales/pipeline')->assertOk();
    }

    public function test_artist_cannot_open_the_sales_pages(): void
    {
        $artistUser = $this->artistUser();

        $this->actingAs($artistUser)->get('/sales')->assertForbidden();
    }

    public function test_sales_root_redirects_to_the_sales_dashboard(): void
    {
        $sales = $this->userWithRole(User::ROLE_SALES, 'sales@vaif.test');

        $this->actingAs($sales)
            ->get('/')
            ->assertRedirect(route('sales.dashboard'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/sales')->assertRedirect(route('login'));
    }

    private function userWithRole(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password-123',
            'role' => $role,
        ]);
    }

    private function artistUser(): User
    {
        $artist = Artist::create([
            'id' => 'a0000000-0000-0000-0000-000000000002',
            'name' => 'Artist',
            'status' => 'live',
            'wa_session_slug' => 'artist-sales-test',
        ]);

        return User::create([
            'name' => 'Artist',
            'email' => 'artist@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }
}
