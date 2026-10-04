<?php

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\RoleName;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * 150 fictional clients owned by the active sales executives.
     */
    public function run(): void
    {
        $owners = User::role(RoleName::SalesExecutive->value)->where('is_active', true)->pluck('id')->all();

        $plans = [
            'Offer an animated emote bundle',
            'Pitch the full stream pack refresh',
            'Upsell alerts and starting screens',
            'Seasonal sub badge update',
        ];

        for ($i = 0; $i < 150; $i++) {
            $roll = fake()->numberBetween(1, 100);
            $status = match (true) {
                $roll <= 60 => ClientStatus::Active,
                $roll <= 80 => ClientStatus::Nurturing,
                $roll <= 92 => ClientStatus::Dormant,
                default => ClientStatus::Lost,
            };

            Client::factory()->create([
                'owner_id' => fake()->randomElement($owners),
                'status' => $status,
                'next_upsell_plan' => $status === ClientStatus::Lost ? null : fake()->randomElement($plans),
                'expected_upsell_on' => $status === ClientStatus::Lost ? null : now()->addDays(fake()->numberBetween(-20, 60))->toDateString(),
                'lost_note' => $status === ClientStatus::Lost ? 'Went with another designer.' : null,
                'notes' => fake()->boolean(30) ? DemoText::clientNote() : null,
            ]);
        }
    }
}
