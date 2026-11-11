<?php

namespace Database\Factories;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Models\Agency;
use App\Models\HrMechanism;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HrMechanism> */
class HrMechanismFactory extends Factory
{
    protected $model = HrMechanism::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'tracking_code' => 'HRM-'.fake()->unique()->numerify('####-#####'),
            'mechanism_type' => MechanismType::Msp,
            'status' => MechanismStatus::Received,
            'copies' => 'eCOPY',
            'document_path' => 'hr-mechanisms/sample.pdf',
            'document_name' => 'sample.pdf',
            'date_submitted' => now(),
        ];
    }

    public function status(MechanismStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
