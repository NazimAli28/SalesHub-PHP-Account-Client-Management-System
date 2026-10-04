<?php

namespace App\Approvals\Appliers;

use App\Models\Client;

/**
 * Plain attribute updates and soft deletes. The Clients module may override update/delete to call its actions.
 */
class ClientApplier extends AttributeApplier
{
    protected function model(): string
    {
        return Client::class;
    }
}
