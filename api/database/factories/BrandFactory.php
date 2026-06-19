<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'timezone'      => 'Europe/Madrid',
            'base_currency' => 'EUR',
            'group_tag'     => null,
            'status'        => 'active',
        ];
    }
}
