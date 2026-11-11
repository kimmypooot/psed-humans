<?php

namespace Database\Factories;

use App\Models\FieldOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FieldOffice> */
class FieldOfficeFactory extends Factory
{
    protected $model = FieldOffice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'FO-'.fake()->unique()->city(), 'code' => strtoupper(fake()->unique()->lexify('???'))];
    }
}
