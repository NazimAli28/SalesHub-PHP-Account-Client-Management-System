<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\Concerns\HasVisibilityScope;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'action', 'approvable_type', 'approvable_id', 'payload', 'before', 'after', 'status',
    'pending_key', 'reason', 'requested_by_id', 'reviewed_by_id', 'reviewed_at',
    'review_comment', 'applied_at', 'failure_message',
])]
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory, HasVisibilityScope;

    /**
     * Morph aliases a team lead may review (for requests from their own team).
     */
    public const TEAM_REVIEWABLE_TYPES = ['lead', 'client', 'order', 'payment'];

    /**
     * The unique `pending_key` of update/delete requests on a record, e.g. "lead:42".
     */
    public static function pendingKeyFor(Model $record): string
    {
        return $record->getMorphClass().':'.$record->getKey();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'before' => 'array',
            'after' => 'array',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * Names of the fields this request changes (changed attributes plus synced relations).
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        $payload = $this->payload ?? [];
        $attributes = $payload['changes'] ?? $payload['attributes'] ?? [];
        $relations = $payload['relations'] ?? [];

        return array_map('strval', [...array_keys($attributes), ...array_keys($relations)]);
    }

    /**
     * Conditional state transition: the attributes are written only while the row still has the
     * expected status, so a request can never be decided twice (second layer behind the row lock).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ApprovalConflictException when the status changed in the meantime
     */
    public function transitionFrom(ApprovalStatus $expected, array $attributes): void
    {
        $this->forceFill($attributes);

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('status', $expected->value)
            ->update($this->getDirty());

        if ($affected !== 1) {
            throw ApprovalConflictException::alreadyDecided();
        }

        $this->refresh();
    }

    /**
     * Pending requests the user may decide. Mirrors ApprovalRequestPolicy::review in SQL (badge and filter).
     *
     * @param  Builder<static>  $query
     */
    public function scopeReviewableBy(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('status'), ApprovalStatus::Pending->value)
            ->where($this->qualifyColumn('requested_by_id'), '!=', $user->id);

        if ($user->can('approvals.review-all')) {
            return;
        }

        if (! $user->can('approvals.review-team') || $user->team_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($this->qualifyColumn('approvable_type'), self::TEAM_REVIEWABLE_TYPES)
            ->whereHas('requester', fn (Builder $q) => $q->where('team_id', $user->team_id));
    }

    protected static function visibilityResource(): string
    {
        return 'approvals';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('requester', fn (Builder $q) => $q->where('team_id', $user->team_id));
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('requested_by_id'), $user->id);
    }
}
