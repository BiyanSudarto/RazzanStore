<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Role;
use PHPUnit\Framework\TestCase;

class AdminRoleTest extends TestCase
{
    public function test_admin_role_check_uses_the_loaded_role_code(): void
    {
        $admin = new Admin();
        $admin->setRelation('role', new Role(['code' => 'super_admin']));

        self::assertTrue($admin->hasRole('super_admin'));
        self::assertFalse($admin->hasRole('admin'));
    }

    public function test_role_check_does_not_match_unknown_roles(): void
    {
        $admin = new Admin();
        $admin->setRelation('role', new Role(['code' => 'admin']));

        self::assertFalse($admin->hasRole('operator'));
    }
}
