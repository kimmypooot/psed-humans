<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'imis_user_id' => fake()->unique()->numberBetween(1000, 999999),
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'role' => UserRole::PsedStaff,
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(['role' => $role]);
    }
}
