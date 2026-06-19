<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'name'             => $name,
            'email'            => $this->faker->unique()->safeEmail(),
            'password'         => static::$password ??= Hash::make('password'),
            'role'             => 'manager',
            'status'           => 'active',
            'display_initials' => strtoupper(substr($name, 0, 2)),
            'timezone'         => 'UTC',
        ];
    }

    public function masterAdmin(): static
    {
        return $this->state(fn () => ['role' => 'master_admin']);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    public function teamMember(): static
    {
        return $this->state(fn () => ['role' => 'team_member']);
    }

    public function brandUser(): static
    {
        return $this->state(fn () => ['role' => 'brand_user']);
    }
}
