<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Interactive admin creation. Prompts for email + password + name and
 * creates (or updates) a master_admin row.
 *
 * Usage:
 *   php artisan helm:create-admin
 *   php artisan helm:create-admin --email=you@x.com --password=secret --name="You"
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'helm:create-admin
        {--email= : Admin email}
        {--password= : Admin password (omit to auto-generate)}
        {--name= : Full name}
        {--role=master_admin : Role to assign}';

    protected $description = 'Create or update a Helm admin user.';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name  = $this->option('name')  ?: $this->ask('Name');
        $role  = $this->option('role');

        $password = $this->option('password');
        $generated = false;
        if (! $password) {
            if ($this->confirm('Auto-generate a random password?', true)) {
                $password = Str::random(16);
                $generated = true;
            } else {
                $password = $this->secret('Password');
            }
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'             => $name,
                'password'         => $password,
                'role'             => $role,
                'status'           => 'active',
                'display_initials' => mb_substr($name, 0, 1),
                'timezone'         => 'UTC',
            ]
        );

        $this->newLine();
        $this->info("✓ Admin {$user->email} ({$role}) ready.");
        if ($generated) {
            $this->newLine();
            $this->warn("Generated password: {$password}");
            $this->warn('Save it now — it will not be shown again.');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
