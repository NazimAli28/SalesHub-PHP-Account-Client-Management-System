<?php

namespace App\Approvals\Appliers;

use App\Actions\Leads\DeleteLead;
use App\Actions\Leads\UpdateLead;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies approved lead changes through the same actions as the direct-write path.
 */
class LeadApplier extends AttributeApplier
{
    public function __construct(
        private readonly UpdateLead $updateLead,
        private readonly DeleteLead $deleteLead,
    ) {}

    protected function model(): string
    {
        return Lead::class;
    }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var Lead $record */
        return $this->updateLead->handle($record, $changes, $relations['services'] ?? null);
    }

    protected function delete(Model $record): Model
    {
        /** @var Lead $record */
        $this->deleteLead->handle($record);

        return $record;
    }
}
