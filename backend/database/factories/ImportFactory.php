<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ImportType::Leads,
            'status' => ImportStatus::Uploaded,
            'original_filename' => 'leads.csv',
            'path' => 'imports/'.fake()->uuid().'.csv',
            'headers' => ['Discord', 'Stage'],
            'total_rows' => 0,
        ];
    }
}
