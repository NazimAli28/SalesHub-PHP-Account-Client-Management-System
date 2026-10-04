<?php

namespace App\Actions\Approvals;

use App\Approvals\ApplierRegistry;
use App\Approvals\ApprovalContext;
use App\Approvals\RecordSnapshot;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Applies an approved request (data-model 6.4). The caller has authorized `review` on the request.
 *
 * Inside one transaction: lock the request row, re-check it is pending (409), lock the target record,
 * run the staleness check (record changed or gone -> status `failed`, 409), apply through the applier
 * registry, then move the request to `approved` with a conditional update. Afterwards: activity entry
 * and an ApprovalDecided notification to the requester.
 */
class ApproveRequest
{
    public const STALE_MESSAGE = 'Record changed since request was submitted';

    public const MISSING_MESSAGE = 'The record no longer exists.';

    public function __construct(private readonly ApplierRegistry $appliers) {}

    /**
     * @throws ApprovalConflictException
     * @throws AuthorizationException
     */
    public function handle(ApprovalRequest $approval, User $reviewer, ?string $comment = null): ApprovalRequest
    {
        /** @var ApprovalRequest $decided */
        $decided = DB::transaction(function () use ($approval, $reviewer, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ApprovalConflictException::alreadyDecided();
            }

            if ($locked->requested_by_id === $reviewer->id) {
                throw new AuthorizationException;
            }

            $review = [
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'pending_key' => null,
            ];

            $record = $this->lockTarget($locked);
            $needsRecord = in_array($locked->action, [ApprovalAction::Update, ApprovalAction::Delete], true);

            if ($needsRecord && ($record === null || RecordSnapshot::isStale($record, $locked->before ?? []))) {
                $locked->transitionFrom(ApprovalStatus::Pending, [
                    ...$review,
                    'status' => ApprovalStatus::Failed,
                    'failure_message' => $record === null ? self::MISSING_MESSAGE : self::STALE_MESSAGE,
                ]);

                return $locked;
            }

            $applier = $this->appliers->for($locked);
            $result = ApprovalContext::run($locked, fn () => $applier->apply($locked, $record));

            $locked->transitionFrom(ApprovalStatus::Pending, [
                ...$review,
                'status' => ApprovalStatus::Approved,
                'applied_at' => now(),
                'approvable_id' => $locked->approvable_id ?? $result?->getKey(),
                'after' => $this->afterSnapshot($locked, $result),
            ]);

            return $locked;
        });

        $decided->load(['requester', 'reviewer']);

        $failed = $decided->status === ApprovalStatus::Failed;
        AuditLogger::approval($failed ? 'failed' : 'approved', $decided, $reviewer);
        $decided->requester?->notify(new ApprovalDecided($decided));

        if ($failed) {
            throw ApprovalConflictException::failed($decided);
        }

        return $decided;
    }

    private function lockTarget(ApprovalRequest $approval): ?Model
    {
        if ($approval->approvable_type === null || $approval->approvable_id === null) {
            return null;
        }

        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($approval->approvable_type);

        return $class === null ? null : $class::query()->whereKey($approval->approvable_id)->lockForUpdate()->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function afterSnapshot(ApprovalRequest $approval, ?Model $result): ?array
    {
        if ($result === null) {
            return null;
        }

        $result->refresh();

        if ($approval->action === ApprovalAction::Update) {
            $payload = $approval->payload ?? [];

            return RecordSnapshot::of(
                $result,
                array_map('strval', array_keys($payload['changes'] ?? [])),
                array_map('strval', array_keys($payload['relations'] ?? [])),
            );
        }

        return RecordSnapshot::of($result);
    }
}
