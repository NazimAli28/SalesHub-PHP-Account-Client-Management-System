<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'discord_username', 'name', 'email', 'payment_name', 'country', 'owner_id', 'status',
    'nurturing_rating', 'next_upsell_plan', 'expected_upsell_on', 'lost_note', 'notes',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'nurturing_rating' => 'integer',
            'expected_upsell_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<ClientNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class);
    }

    /**
     * @return HasManyThrough<Payment, Order, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Order::class);
    }

    /**
     * Adds `lifetime_value_cents`: the sum of paid payments across all orders.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithLifetimeValue(Builder $query): void
    {
        $query->withSum(
            ['payments as lifetime_value_cents' => fn (Builder $q) => $q->where('payments.status', PaymentStatus::Paid->value)],
            'amount_cents',
        );
    }

    protected static function visibilityResource(): string
    {
        return 'clients';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        $teamUsers = fn (Builder $q) => $q->where('team_id', $user->team_id);

        return $query->where(function (Builder $q) use ($teamUsers): void {
            $q->whereHas('owner', $teamUsers)
                ->orWhereHas('leads', fn (Builder $l) => $l->whereHas('owner', $teamUsers))
                ->orWhereHas('orders', fn (Builder $o) => $o->whereHas('owner', $teamUsers));
        });
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where($this->qualifyColumn('owner_id'), $user->id)
                ->orWhereHas('leads', fn (Builder $l) => $l->where('owner_id', $user->id))
                ->orWhereHas('orders', fn (Builder $o) => $o->where('owner_id', $user->id));
        });
    }
}
