<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationSeeder extends Seeder
{
    /**
     * About 60 database notifications (12 each for five demo users), a mix of read and unread.
     */
    public function run(): void
    {
        $messages = [
            ['App\\Notifications\\ApprovalSubmitted', 'A new change request is waiting for review.'],
            ['App\\Notifications\\ApprovalDecided', 'Your change request was approved.'],
            ['App\\Notifications\\ApprovalDecided', 'Your change request was rejected.'],
            ['App\\Notifications\\PaymentOverdue', 'An installment is overdue.'],
            ['App\\Notifications\\AccountStandingChanged', 'A platform account on your workstation changed standing.'],
        ];

        $users = User::query()->whereIn('username', ['admin', 'support', 'tl', 'agent1', 'agent2'])->get();
        $rows = [];

        foreach ($users as $user) {
            for ($i = 0; $i < 12; $i++) {
                [$type, $message] = fake()->randomElement($messages);
                $createdAt = now()->subHours(fake()->numberBetween(1, 24 * 30));

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'notifiable_type' => 'user',
                    'notifiable_id' => $user->id,
                    'data' => json_encode(['message' => $message]),
                    'read_at' => fake()->boolean(40) ? $createdAt->copy()->addHours(2) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        }

        DB::table('notifications')->insert($rows);
    }
}
