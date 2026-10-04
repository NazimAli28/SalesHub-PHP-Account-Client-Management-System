<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
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
