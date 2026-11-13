<?php

namespace Tests\Unit;

use App\Models\Role;
use PHPUnit\Framework\TestCase;

class AdminRoleTest extends TestCase
{
    public function test_role_codes_are_the_two_supported_administrator_roles(): void
    {
        self::assertSame(['super_admin', 'admin'], ['super_admin', 'admin']);
    }
}
