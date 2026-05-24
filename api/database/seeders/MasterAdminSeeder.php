<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Bootstrap a master admin so the team can sign in. Idempotent — running
 * twice doesn't create duplicates. Update the credentials below for
 * production deployments (or use `php artisan helm:create-admin` instead).
 */
class MasterAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SEED_ADMIN_EMAIL', 'kanwartalha009@gmail.com');
        $password = env('SEED_ADMIN_PASSWORD', 'helm-admin-2026');
        $name     = env('SEED_ADMIN_NAME', 'Kanwar');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'             => $name,
                'password'         => $password,   // hashed by the `password` cast on the model
                'role'             => 'master_admin',
                'status'           => 'active',
                'display_initials' => mb_substr($name, 0, 1),
                'timezone'         => 'UTC',
            ]
        );

        $this->command->info('');
        $this->command->info('  Master admin ready:');
        $this->command->info('  Email:    ' . $user->email);
        $this->command->info('  Password: ' . $password);
        $this->command->info('');
        $this->command->warn('  Change this password from Settings → Account on first sign-in.');
        $this->command->info('');
    }
}
