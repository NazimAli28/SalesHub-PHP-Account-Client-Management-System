<?php

namespace App\Approvals\Appliers;

use App\Actions\Clients\DeleteClient;
use App\Actions\Clients\UpdateClient;
use App\Models\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies approved client changes through the same actions as the direct-write path.
 */
class ClientApplier extends AttributeApplier
{
    public function __construct(
        private readonly UpdateClient $updateClient,
        private readonly DeleteClient $deleteClient,
    ) {}

    protected function model(): string
    {
        return Client::class;
    }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var Client $record */
        return $this->updateClient->handle($record, $changes);
    }

    protected function delete(Model $record): Model
    {
        /** @var Client $record */
        $this->deleteClient->handle($record);

        return $record;
    }
}
