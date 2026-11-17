<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fresh-install seeder: exactly one bootstrap administrator and NO demo data.
 * Everything else (ERPNext connection, POS profiles, users, catalog) arrives
 * through the first-run setup flow. For demo data use DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('forkpos.bootstrap_admin');

        User::firstOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'password' => $admin['password'],
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        $this->command?->info("Bootstrap admin ready: {$admin['email']} (password must be changed on first login).");
    }
}
