<?php

namespace App\Approvals\Appliers;

use App\Approvals\Contracts\ApprovalApplier;
use App\Enums\ApprovalAction;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * Generic applier for plain records:
 *   create -> Model::create(payload.attributes) + sync payload.relations
 *   update -> fill(payload.changes)->save() + sync payload.relations
 *   delete -> soft delete
 *
 * Extend it and return the model class from model(). Override create/update/delete to call the
 * module's Action classes when a write needs more than a fill+save (e.g. order totals, lead services).
 */
abstract class AttributeApplier implements ApprovalApplier
{
    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    public function apply(ApprovalRequest $approval, ?Model $record): ?Model
    {
        $payload = $approval->payload ?? [];
        /** @var array<string, list<int>> $relations */
        $relations = $payload['relations'] ?? [];

        return match ($approval->action) {
            ApprovalAction::Create => $this->create($payload['attributes'] ?? [], $relations),
            ApprovalAction::Update => $this->update($this->target($record), $payload['changes'] ?? [], $relations),
            ApprovalAction::Delete => $this->delete($this->target($record)),
            ApprovalAction::RequestAccounts => throw new LogicException('request_accounts is handled by RequestAccountsApplier.'),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<int>>  $relations
     */
    protected function create(array $attributes, array $relations): Model
    {
        $record = $this->model()::query()->create($attributes);
        $this->syncRelations($record, $relations);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, list<int>>  $relations
     */
    protected function update(Model $record, array $changes, array $relations): Model
    {
        $record->fill($changes)->save();
        $this->syncRelations($record, $relations);

        return $record;
    }

    protected function delete(Model $record): Model
    {
        $record->delete();

        return $record;
    }

    /**
     * @param  array<string, list<int>>  $relations
     */
    protected function syncRelations(Model $record, array $relations): void
    {
        foreach ($relations as $name => $ids) {
            $relation = $record->{$name}();

            if (! $relation instanceof BelongsToMany) {
                throw new LogicException("Approval relation [{$name}] must be a BelongsToMany relation.");
            }

            $relation->sync($ids);
        }

        if ($relations !== [] && ! $record->wasChanged()) {
            $record->touch();
        }
    }

    private function target(?Model $record): Model
    {
        if (! $record instanceof Model || ! is_a($record, $this->model())) {
            throw new LogicException('The approval request has no target '.$this->model().' record.');
        }

        return $record;
    }
}
