<?php

namespace App\Actions\Clients;

use App\Models\Client;

class DeleteClient
{
    /**
     * Soft delete; leads and orders keep their client reference.
     */
    public function handle(Client $client): void
    {
        $client->delete();
    }
}
