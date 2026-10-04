<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientNote>
 */
class ClientNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'body' => DemoText::clientNoteBody(),
            'is_pinned' => false,
        ];
    }

    public function pinned(): static
    {
        return $this->state(['is_pinned' => true]);
    }
}
