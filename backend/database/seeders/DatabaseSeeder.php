<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Activitylog\Support\ActivityLogStatus;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (reproducible: fixed Faker seed, dates relative to now()).
     */
    public function run(): void
    {
        fake()->seed(2026);

        // Model activity is not logged while seeding; ActivityLogSeeder writes the demo entries.
        $logStatus = app(ActivityLogStatus::class);
        $logStatus->disable();

        $this->call(RolesAndPermissionsSeeder::class);

        $this->call([
            TeamSeeder::class,
            WorkstationSeeder::class,
            UserSeeder::class,
            ServiceSeeder::class,
            PlatformAccountSeeder::class,
            SocialAccountSeeder::class,
            ClientSeeder::class,
            LeadSeeder::class,
            OrderSeeder::class,
            ApprovalRequestSeeder::class,
            NotificationSeeder::class,
        ]);

        $logStatus->enable();
        $this->call(ActivityLogSeeder::class);
    }
}
