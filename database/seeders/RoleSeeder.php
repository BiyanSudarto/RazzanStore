<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(
            ['code' => 'super_admin'],
            ['name' => 'Super Admin'],
        );

        Role::firstOrCreate(
            ['code' => 'admin'],
            ['name' => 'Admin'],
        );
    }
}
