<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => 'owner', // Valid roles: owner, admin, member
            'status' => 'active',
            'created_by' => User::factory(),
        ];
    }
}
