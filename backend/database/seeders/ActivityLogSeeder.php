<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Order;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityLogSeeder extends Seeder
{
    /**
     * About 300 plausible audit entries (written directly, so no secrets and no model events).
     */
    public function run(): void
    {
        $users = User::query()->where('is_active', true)->pluck('id')->all();
        $leads = array_values(array_map('intval', Lead::query()->limit(60)->pluck('id')->all()));
        $orders = array_values(array_map('intval', Order::query()->limit(40)->pluck('id')->all()));
        $accounts = PlatformAccount::query()->limit(30)->pluck('id')->all();

        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $at = now()->subMinutes(fake()->numberBetween(5, 60 * 24 * 170));
            $causer = fake()->randomElement($users);
            $roll = fake()->numberBetween(1, 100);

            $row = match (true) {
                $roll <= 55 => $this->modelEntry($leads, $orders),
                $roll <= 80 => [
                    'log_name' => 'auth',
                    'description' => $event = fake()->randomElement(['login', 'login', 'login', 'logout', 'login_failed']),
                    'event' => $event,
                    'subject_type' => 'user',
                    'subject_id' => $causer,
                    'properties' => ['ip' => fake()->ipv4(), 'user_agent' => 'Mozilla/5.0'],
                ],
                $roll <= 90 => [
                    'log_name' => 'security',
                    'description' => 'credentials_revealed',
                    'event' => 'credentials_revealed',
                    'subject_type' => 'platform_account',
                    'subject_id' => fake()->randomElement($accounts),
                    'properties' => ['fields' => ['discord_password'], 'ip' => fake()->ipv4()],
                ],
                default => [
                    'log_name' => 'approval',
                    'description' => $event = fake()->randomElement(['submitted', 'approved', 'rejected']),
                    'event' => $event,
                    'subject_type' => 'lead',
                    'subject_id' => fake()->randomElement($leads),
                    'properties' => ['action' => 'update', 'approvable_type' => 'lead'],
                ],
            };

            $rows[] = [
                'log_name' => $row['log_name'],
                'description' => $row['description'],
                'event' => $row['event'],
                'subject_type' => $row['subject_type'],
                'subject_id' => $row['subject_id'],
                'causer_type' => 'user',
                'causer_id' => $causer,
                'attribute_changes' => $row['attribute_changes'] ?? null,
                'properties' => json_encode($row['properties'] ?? []),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('activity_log')->insert($chunk);
        }
    }

    /**
     * @param  list<int>  $leads
     * @param  list<int>  $orders
     * @return array<string, mixed>
     */
    private function modelEntry(array $leads, array $orders): array
    {
        if (fake()->boolean(60)) {
            $stages = ['new', 'engaged', 'portfolio_shared', 'quoted', 'payment_pending'];
            $old = fake()->randomElement(array_slice($stages, 0, 4));

            return [
                'log_name' => 'model',
                'description' => 'updated',
                'event' => 'updated',
                'subject_type' => 'lead',
                'subject_id' => fake()->randomElement($leads),
                'attribute_changes' => json_encode([
                    'attributes' => ['stage' => $stages[(int) array_search($old, $stages, true) + 1] ?? $old],
                    'old' => ['stage' => $old],
                ]),
            ];
        }

        [$from, $to] = fake()->randomElement([
            ['pending_payment', 'in_progress'],
            ['in_progress', 'delivered'],
            ['delivered', 'completed'],
        ]);

        return [
            'log_name' => 'model',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => 'order',
            'subject_id' => fake()->randomElement($orders),
            'attribute_changes' => json_encode([
                'attributes' => ['status' => $to],
                'old' => ['status' => $from],
            ]),
        ];
    }
}
