<?php

namespace App\Models;

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'client_id', 'owner_id', 'closer_id', 'platform_account_id', 'stage', 'stage_changed_at',
    'contacted_on', 'estimated_value_cents', 'currency', 'last_message', 'next_follow_up_on',
    'lost_reason', 'lost_note', 'order_id',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            $lead->stage_changed_at ??= now();
        });

        static::updating(function (Lead $lead): void {
            if ($lead->isDirty('stage') && ! $lead->isDirty('stage_changed_at')) {
                $lead->stage_changed_at = now();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'lost_reason' => LeadLostReason::class,
            'stage_changed_at' => 'datetime',
            'contacted_on' => 'date:Y-m-d',
            'next_follow_up_on' => 'date:Y-m-d',
            'estimated_value_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closer_id');
    }

    /**
     * @return BelongsTo<PlatformAccount, $this>
     */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'lead_service');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function visibilityResource(): string
    {
        return 'leads';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('owner', fn (Builder $q) => $q->where('team_id', $user->team_id));
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('owner_id'), $user->id);
    }
}
