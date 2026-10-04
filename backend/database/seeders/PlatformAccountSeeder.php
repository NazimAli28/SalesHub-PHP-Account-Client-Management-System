<?php

namespace Database\Seeders;

use App\Enums\AccountStanding;
use App\Models\PlatformAccount;
use App\Models\Workstation;
use Illuminate\Database\Seeder;

class PlatformAccountSeeder extends Seeder
{
    /**
     * 60 accounts: 48 assigned (4 per workstation), 12 unassigned.
     * Standings: 42 active, 7 limited, 5 spam, 3 violation, 3 disabled.
     */
    public function run(): void
    {
        $stations = Workstation::query()->orderBy('id')->get();

        $standings = [
            ...array_fill(0, 42, AccountStanding::Active),
            ...array_fill(0, 7, AccountStanding::Limited),
            ...array_fill(0, 5, AccountStanding::Spam),
            ...array_fill(0, 3, AccountStanding::Violation),
            ...array_fill(0, 3, AccountStanding::Disabled),
        ];
        mt_srand(2026);
        shuffle($standings);

        $batches = [
            now()->subDays(170)->toDateString(),
            now()->subDays(120)->toDateString(),
            now()->subDays(75)->toDateString(),
            now()->subDays(30)->toDateString(),
        ];

        for ($i = 0; $i < 60; $i++) {
            $factory = PlatformAccount::factory()->standing($standings[$i]);

            if ($i < 48) {
                $factory = $factory->assigned($stations[$i % 12]);
            }

            $factory->create([
                'batch_date' => $batches[$i % 4],
                'discord_email' => $i % 3 === 0 ? fake()->unique()->userName().'.discord@example.com' : null,
            ]);
        }
    }
}
