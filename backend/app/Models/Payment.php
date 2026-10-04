<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_id', 'sequence', 'amount_cents', 'currency', 'due_date', 'status', 'paid_at',
    'method', 'reference', 'recorded_by_id', 'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount_cents' => 'integer',
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'due_date' => 'date:Y-m-d',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * Scheduled payments whose due date is before today.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), PaymentStatus::Scheduled->value)
            ->where($this->qualifyColumn('due_date'), '<', today()->toDateString());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDueBetween(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $query->whereBetween($this->qualifyColumn('due_date'), [
            $from instanceof CarbonInterface ? $from->toDateString() : $from,
            $to instanceof CarbonInterface ? $to->toDateString() : $to,
        ]);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->status === PaymentStatus::Scheduled
                && $this->due_date->lt(today()),
        );
    }

    protected static function visibilityResource(): string
    {
        return 'orders';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('order', fn (Builder $q) => $q->visibleTo($user));
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('order', fn (Builder $q) => $q->visibleTo($user));
    }
}
