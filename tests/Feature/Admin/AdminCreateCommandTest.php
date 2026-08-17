<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_administrator_with_a_login(): void
    {
        $this->artisan('admin:create', [
            'login' => 'case_admin',
            '--email' => 'admin@example.com',
            '--name' => 'Case Admin',
            '--password' => 'very-secure-password',
        ])->assertSuccessful();

        $administrator = User::query()->where('login', 'case_admin')->firstOrFail();

        $this->assertTrue($administrator->is_admin);
        $this->assertSame('admin@example.com', $administrator->email);
        $this->assertSame('Case Admin', $administrator->name);
    }

    public function test_command_assigns_a_login_to_the_existing_administrator(): void
    {
        $administrator = User::factory()->administrator()->create(['login' => null]);

        $this->artisan('admin:create', [
            'login' => 'existing_admin',
            '--email' => $administrator->email,
            '--name' => $administrator->name,
        ])->assertSuccessful();

        $this->assertSame('existing_admin', $administrator->refresh()->login);
        $this->assertSame(1, User::query()->where('is_admin', true)->count());
    }
}
