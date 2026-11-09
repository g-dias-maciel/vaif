<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin VAIF',
            'email' => 'admin@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);
    }

    public function test_staff_sees_internal_users_but_not_artist_logins(): void
    {
        $sales = $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);
        $artist = $this->artistUser();

        $this->actingAs($this->admin)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('Admin VAIF')
            ->assertSee('Vendas')
            ->assertDontSee($artist->email);
    }

    public function test_sales_cannot_access_the_team_page(): void
    {
        $sales = $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);

        $this->actingAs($sales)->get(route('team.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('team.create'))->assertForbidden();
        $this->actingAs($sales)->post(route('team.store'), [])->assertForbidden();
    }

    public function test_staff_can_create_a_sales_user(): void
    {
        $this->actingAs($this->admin)
            ->post(route('team.store'), [
                'name' => 'Novo Vendedor',
                'email' => 'NOVO.VENDEDOR@vaif.test',
                'role' => User::ROLE_SALES,
                'password' => 'senha-segura-123',
                'password_confirmation' => 'senha-segura-123',
            ])
            ->assertRedirect(route('team.index'));

        $user = User::where('email', 'novo.vendedor@vaif.test')->firstOrFail();

        $this->assertSame('Novo Vendedor', $user->name);
        $this->assertSame(User::ROLE_SALES, $user->role);
        $this->assertNull($user->artist_id);
        $this->assertTrue(Hash::check('senha-segura-123', $user->password));
    }

    public function test_team_validation_rejects_duplicate_email_and_weak_password(): void
    {
        $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);

        $this->actingAs($this->admin)
            ->post(route('team.store'), [
                'name' => 'Duplicado',
                'email' => 'VENDAS@vaif.test',
                'role' => User::ROLE_SALES,
                'password' => '123',
                'password_confirmation' => '123',
            ])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(2, User::count());
    }

    public function test_staff_can_update_a_user_and_change_the_password(): void
    {
        $sales = $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);

        $this->actingAs($this->admin)
            ->put(route('team.update', $sales), [
                'name' => 'Vendas Atualizado',
                'email' => 'vendas.novo@vaif.test',
                'role' => User::ROLE_STAFF,
                'password' => 'nova-senha-123',
                'password_confirmation' => 'nova-senha-123',
            ])
            ->assertRedirect(route('team.index'));

        $sales->refresh();

        $this->assertSame('Vendas Atualizado', $sales->name);
        $this->assertSame('vendas.novo@vaif.test', $sales->email);
        $this->assertSame(User::ROLE_STAFF, $sales->role);
        $this->assertTrue(Hash::check('nova-senha-123', $sales->password));
    }

    public function test_update_without_password_keeps_the_current_password(): void
    {
        $sales = $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);
        $before = $sales->password;

        $this->actingAs($this->admin)
            ->put(route('team.update', $sales), [
                'name' => 'Vendas Sem Senha',
                'email' => 'vendas@vaif.test',
                'role' => User::ROLE_SALES,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('team.index'));

        $this->assertSame($before, $sales->fresh()->password);
    }

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        $this->actingAs($this->admin)
            ->put(route('team.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => User::ROLE_SALES,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($this->admin)
            ->delete(route('team.destroy', $this->admin))
            ->assertSessionHasErrors('user');

        $this->assertSame(User::ROLE_STAFF, $this->admin->fresh()->role);
    }

    public function test_deleting_a_sales_user_transfers_their_prospects_to_the_admin(): void
    {
        $sales = $this->user('Vendas', 'vendas@vaif.test', User::ROLE_SALES);

        $prospect = Prospect::create([
            'name' => 'Lead do Vendedor',
            'whatsapp_e164' => '+5511966665555',
            'status' => Prospect::STATUS_BACKLOG,
            'source' => 'notion',
            'owner_id' => $sales->id,
        ]);

        $event = TeamEvent::create([
            'title' => 'Reunião do Vendedor',
            'type' => TeamEvent::TYPE_SALES,
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDay()->startOfHour()->addHour(),
            'owner_id' => $sales->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('team.destroy', $sales))
            ->assertRedirect(route('team.index'));

        $this->assertNull(User::find($sales->id));
        $this->assertSame($this->admin->id, $prospect->fresh()->owner_id);
        $this->assertSame($this->admin->id, $event->fresh()->owner_id);
    }

    public function test_artist_logins_cannot_be_managed_from_the_team_screen(): void
    {
        $artist = $this->artistUser();

        $this->actingAs($this->admin)
            ->get(route('team.edit', $artist))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('team.destroy', $artist))
            ->assertNotFound();
    }

    private function artistUser(): User
    {
        $artist = \App\Models\Artist::create([
            'name' => 'Artista Login',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'artista-login-'.uniqid(),
        ]);

        return User::create([
            'name' => 'Artista Login',
            'email' => 'artista-'.uniqid().'@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password-123',
            'role' => $role,
        ]);
    }
}
