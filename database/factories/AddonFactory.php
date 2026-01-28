<?php

namespace Database\Factories;

use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Addon>
 */
class AddonFactory extends Factory
{
    protected $model = Addon::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'category' => 'utility',
            'logo' => 'default.png',
            'is_active' => 1,
            'status' => 1,
        ];
    }
}
