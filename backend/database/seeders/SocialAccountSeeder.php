<?php

namespace Database\Seeders;

use App\Enums\SocialPlatform;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use Illuminate\Database\Seeder;

class SocialAccountSeeder extends Seeder
{
    /**
     * Two social accounts per platform account (120 in total).
     */
    public function run(): void
    {
        foreach (PlatformAccount::query()->orderBy('id')->get() as $account) {
            foreach (fake()->randomElements(SocialPlatform::cases(), 2) as $platform) {
                SocialAccount::factory()->create([
                    'platform_account_id' => $account->id,
                    'platform' => $platform,
                    'login_email' => $account->email,
                ]);
            }
        }
    }
}
