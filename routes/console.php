<?php

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('razzanstore:about', function (): void {
    $this->info('RazzanStore application foundation is ready.');
})->purpose('Display RazzanStore application information');

Artisan::command('razzanstore:admin:create {--role=super_admin : Role: super_admin or admin}', function (): int {
    $roleCode = (string) $this->option('role');
    $roles = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
    ];

    if (! array_key_exists($roleCode, $roles)) {
        $this->error('Role tidak valid. Gunakan --role=super_admin atau --role=admin.');

        return 1;
    }

    $roleName = $roles[$roleCode];
    $name = trim((string) $this->ask('Nama '.$roleName));
    $email = strtolower(trim((string) $this->ask('Email '.$roleName)));

    $validator = Validator::make(
        ['name' => $name, 'email' => $email],
        [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
        ],
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $password = (string) $this->secret('Password (minimal 12 karakter)');
    $confirmation = (string) $this->secret('Ulangi password');

    if (strlen($password) < 12 || strlen($password) > 128 || ! hash_equals($password, $confirmation)) {
        $this->error('Password harus 12–128 karakter dan konfirmasi harus sama.');

        return 1;
    }

    DB::transaction(function () use ($name, $email, $password, $roleCode, $roleName): void {
        $role = Role::firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleName],
        );

        Admin::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
    });

    $this->info($roleName.' berhasil dibuat. Kredensial tidak ditampilkan kembali.');

    return 0;
})->purpose('Create an administrator interactively without default credentials');
