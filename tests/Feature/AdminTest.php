<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_cannot_open_admin_pages(): void
    {
        foreach ([User::factory()->create(), User::factory()->reviewer()->create()] as $user) {
            $this->actingAs($user)->get('/admin/users')->assertForbidden();
            $this->actingAs($user)->get('/admin/audit-log')->assertForbidden();
        }
    }

    public function test_admin_can_open_admin_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/audit-log')->assertOk();
    }

    public function test_admin_can_change_another_users_role_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $dev = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.role', $dev), ['role' => 'reviewer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('reviewer', $dev->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_changed', 'user_id' => $admin->id]);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.users.role', $admin), ['role' => 'developer'])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $dev = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.role', $dev), ['role' => 'superuser'])
            ->assertSessionHasErrors('role');
    }

    public function test_dashboard_loads_for_every_role(): void
    {
        foreach ([User::factory()->create(), User::factory()->reviewer()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Projects');
        }
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
