<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(string $role = 'super_admin', bool $active = true): Admin
    {
        $roleModel = Role::create([
            'code' => $role,
            'name' => $role === 'super_admin' ? 'Super Admin' : 'Admin',
        ]);

        return Admin::create([
            'role_id' => $roleModel->id,
            'name' => 'Razzan Admin',
            'email' => $role.'@razzanstore.test',
            'password' => 'StrongTestPassword123!',
            'is_active' => $active,
        ]);
    }

    public function test_admin_can_login_and_access_dashboard(): void
    {
        $admin = $this->createAdmin();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'StrongTestPassword123!',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Dashboard'));
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(active: false);

        $this->from('/admin/login')->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'StrongTestPassword123!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_admin_logout_invalidates_session(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/logout')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }
}
