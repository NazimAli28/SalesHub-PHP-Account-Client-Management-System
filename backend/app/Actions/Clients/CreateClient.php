<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;

class CreateClient
{
    /**
     * The owner defaults to the acting user.
     *
     * @param  array<string, mixed>  $data  Validated client attributes.
     */
    public function handle(User $actor, array $data): Client
    {
        $data['owner_id'] ??= $actor->id;
        $data['status'] ??= ClientStatus::Active->value;

        return Client::query()->create($data);
    }
}
