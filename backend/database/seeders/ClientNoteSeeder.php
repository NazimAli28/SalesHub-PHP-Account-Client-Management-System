<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Seeder;

class ClientNoteSeeder extends Seeder
{
    /**
     * 0 to 4 notes per client, written by the client's owner or a support user, spread over the last months.
     */
    public function run(): void
    {
        $support = User::query()->where('username', 'support')->value('id');

        Client::query()->orderBy('id')->each(function (Client $client) use ($support): void {
            $count = fake()->numberBetween(0, 4);

            for ($i = 0; $i < $count; $i++) {
                $at = now()->subDays(fake()->numberBetween(1, 150))->subMinutes(fake()->numberBetween(0, 1000));

                ClientNote::query()->create([
                    'client_id' => $client->id,
                    'user_id' => $client->owner_id !== null && fake()->boolean(75) ? $client->owner_id : ($support ?? $client->owner_id),
                    'body' => DemoText::clientNoteBody(),
                    'is_pinned' => $i === 0 && fake()->boolean(20),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        });
    }
}
