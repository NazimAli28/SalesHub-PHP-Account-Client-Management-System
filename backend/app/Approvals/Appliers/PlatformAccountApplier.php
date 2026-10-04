<?php

namespace App\Approvals\Appliers;

use App\Actions\PlatformAccounts\DeletePlatformAccount;
use App\Actions\PlatformAccounts\UpdatePlatformAccount;
use App\Models\PlatformAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies approved updates through UpdatePlatformAccount so standing_changed_at / assigned_at are set
 * when the change is applied, not when it was requested. Credential columns never appear in payloads
 * (they are support/admin direct writes).
 */
class PlatformAccountApplier extends AttributeApplier
{
    public function __construct(
        private readonly UpdatePlatformAccount $updateAccount,
        private readonly DeletePlatformAccount $deleteAccount,
    ) {}

    protected function model(): string
    {
        return PlatformAccount::class;
    }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var PlatformAccount $record */
        return $this->updateAccount->handle($record, $this->updateAccount->prepare($record, $changes));
    }

    protected function delete(Model $record): Model
    {
        /** @var PlatformAccount $record */
        $this->deleteAccount->handle($record);

        return $record;
    }
}
