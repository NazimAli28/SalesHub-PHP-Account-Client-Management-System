<?php

namespace App\Actions\Clients;

use App\Models\Client;

/**
 * The single write path for client changes: used by the controller (direct update) and by ClientApplier.
 */
class UpdateClient
{
    /**
     * Pure: turns validated input into the change set that is applied or queued.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Client $client, array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes  Output of prepare().
     */
    public function handle(Client $client, array $changes): Client
    {
        $client->fill($changes)->save();

        return $client;
    }
}
