<?php

namespace App\Actions\Approvals;

use App\Approvals\ApplierRegistry;
use App\Approvals\RecordSnapshot;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Concerns\HasApprovals;
use App\Models\User;
use App\Notifications\ApprovalSubmitted;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Queues a change for maker-checker review (data-model section 6). Callers have already validated the
 * data with the same Form Request rules as a direct write and checked the `requestChange` ability.
 */
class SubmitChangeRequest
{
    public const ALREADY_PENDING = 'This record already has a pending change.';

    public function __construct(private readonly ApplierRegistry $appliers) {}

    /**
     * Only attributes that really differ are stored. 422 when nothing changes or a request is already pending.
     *
     * @param  array<string, mixed>  $changes  Validated attribute changes.
     * @param  array<string, list<int>>  $relations  BelongsToMany relation name => full new ID list.
     */
    public function update(User $requester, Model $record, array $changes, array $relations = [], ?string $reason = null): ApprovalRequest
    {
        $this->ensureApprovable($record);

        $changes = $this->onlyRealChanges($record, $changes);
        $relations = $this->onlyRealRelationChanges($record, $relations);

        if ($changes === [] && $relations === []) {
            throw ValidationException::withMessages(['changes' => 'There are no changes to submit.']);
        }

        $payload = ['changes' => $changes];
        if ($relations !== []) {
            $payload['relations'] = $relations;
        }

        return $this->submit($requester, [
            'action' => ApprovalAction::Update,
            'approvable_type' => $record->getMorphClass(),
            'approvable_id' => $record->getKey(),
            'payload' => $payload,
            'before' => RecordSnapshot::of($record, array_keys($changes), array_keys($relations)),
            'pending_key' => ApprovalRequest::pendingKeyFor($record),
            'reason' => $reason,
        ]);
    }

    public function delete(User $requester, Model $record, ?string $reason = null): ApprovalRequest
    {
        $this->ensureApprovable($record);

        return $this->submit($requester, [
            'action' => ApprovalAction::Delete,
            'approvable_type' => $record->getMorphClass(),
            'approvable_id' => $record->getKey(),
            'payload' => [],
            'before' => RecordSnapshot::of($record),
            'pending_key' => ApprovalRequest::pendingKeyFor($record),
            'reason' => $reason,
        ]);
    }

    /**
     * A record that should only exist once approved. No module needs this yet; it is here so one can opt in.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<int>>  $relations
     */
    public function create(User $requester, string $modelClass, array $attributes, array $relations = [], ?string $reason = null): ApprovalRequest
    {
        $alias = (new $modelClass)->getMorphClass();
        $this->ensureApplierExists($alias);

        return $this->submit($requester, [
            'action' => ApprovalAction::Create,
            'approvable_type' => $alias,
            'approvable_id' => null,
            'payload' => array_filter(['attributes' => $attributes, 'relations' => $relations]),
            'before' => null,
            'pending_key' => null,
            'reason' => $reason,
        ]);
    }

    /**
     * "Request new platform accounts" (spec item 29): no target record, any number may be pending.
     */
    public function requestAccounts(User $requester, int $workstationId, int $quantity, ?string $note = null, ?string $reason = null): ApprovalRequest
    {
        return $this->submit($requester, [
            'action' => ApprovalAction::RequestAccounts,
            'approvable_type' => null,
            'approvable_id' => null,
            'payload' => ['workstation_id' => $workstationId, 'quantity' => $quantity, 'note' => $note],
            'before' => null,
            'pending_key' => null,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submit(User $requester, array $attributes): ApprovalRequest
    {
        $pendingKey = $attributes['pending_key'];

        if ($pendingKey !== null && ApprovalRequest::query()->where('pending_key', $pendingKey)->exists()) {
            throw $this->alreadyPending();
        }

        try {
            // The savepoint keeps an outer transaction usable if the unique pending_key index rejects a race.
            $approval = DB::transaction(fn () => ApprovalRequest::query()->create([
                ...$attributes,
                'status' => ApprovalStatus::Pending,
                'requested_by_id' => $requester->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyPending();
        }

        $approval->setRelation('requester', $requester);

        AuditLogger::approval('submitted', $approval, $requester);
        Notification::send(ReviewerResolver::for($approval), new ApprovalSubmitted($approval));

        return $approval;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function onlyRealChanges(Model $record, array $changes): array
    {
        $probe = (clone $record)->fill($changes);
        $dirty = array_keys($probe->getDirty());

        return array_intersect_key($changes, array_flip($dirty));
    }

    /**
     * @param  array<string, list<int>>  $relations
     * @return array<string, list<int>>
     */
    private function onlyRealRelationChanges(Model $record, array $relations): array
    {
        $changed = [];

        foreach ($relations as $name => $ids) {
            $new = array_values(array_unique(array_map('intval', $ids)));
            sort($new);

            if ($new !== RecordSnapshot::relatedIds($record, $name)) {
                $changed[$name] = $new;
            }
        }

        return $changed;
    }

    private function ensureApprovable(Model $record): void
    {
        if (! $record->exists || ! in_array(HasApprovals::class, class_uses_recursive($record), true)) {
            throw new LogicException($record::class.' must be a saved model using HasApprovals.');
        }

        if (Relation::getMorphedModel($record->getMorphClass()) === null) {
            throw new LogicException($record::class.' needs a morph alias in AppServiceProvider.');
        }

        $this->ensureApplierExists($record->getMorphClass());
    }

    private function ensureApplierExists(string $alias): void
    {
        if (! $this->appliers->supports($alias)) {
            $this->appliers->forType($alias); // throws with the expected class name
        }
    }

    private function alreadyPending(): ValidationException
    {
        return ValidationException::withMessages(['approval' => self::ALREADY_PENDING]);
    }
}
