<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_non_administrators_cannot_visit_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_administrator_can_visit_the_dashboard(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }
}
