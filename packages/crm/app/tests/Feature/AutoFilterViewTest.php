<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoFilterViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_forms_are_marked_for_auto_submit(): void
    {
        $staff = User::create([
            'name' => 'Admin Filters',
            'email' => 'admin-filters@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        foreach ([
            route('finance.months'),
            route('finance.expenses.index'),
            route('finance.invoices.index'),
            route('finance.payments.index'),
            route('finance.clients'),
            route('calendar.index'),
            route('sales.backlog'),
            route('sales.pipeline'),
            route('promotions.index'),
        ] as $url) {
            $this->actingAs($staff)
                ->get($url)
                ->assertOk()
                ->assertSee('data-auto-filter', false);
        }
    }
}
