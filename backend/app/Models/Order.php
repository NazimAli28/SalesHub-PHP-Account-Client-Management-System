<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use App\Support\OrderNumberGenerator;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number', 'client_id', 'owner_id', 'closer_id', 'team_id', 'platform_account_id',
    'parent_order_id', 'type', 'status', 'currency', 'subtotal_cents', 'discount_cents',
    'total_cents', 'ordered_on', 'delivered_at', 'notes',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->order_number)) {
                $order->order_number = OrderNumberGenerator::next($order->ordered_on);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'ordered_on' => 'date:Y-m-d',
            'delivered_at' => 'datetime',
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
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<PlatformAccount, $this>
     */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function upsells(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<Lead, $this>
     */
    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    /**
     * Adds `paid_cents`: the sum of paid payments (balance = total - paid).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithPaymentTotals(Builder $query): void
    {
        $query->withSum(
            ['payments as paid_cents' => fn (Builder $q) => $q->where('payments.status', PaymentStatus::Paid->value)],
            'amount_cents',
        );
    }

    /**
     * Amount paid so far, derived from paid payments (uses the `paid_cents` aggregate when loaded).
     */
    public function paidCents(): int
    {
        if (array_key_exists('paid_cents', $this->attributes)) {
            return (int) $this->attributes['paid_cents'];
        }

        return (int) $this->payments()->where('status', PaymentStatus::Paid->value)->sum('amount_cents');
    }

    /**
     * Outstanding balance, never negative.
     */
    public function balanceCents(): int
    {
        return max(0, (int) $this->total_cents - $this->paidCents());
    }

    protected static function visibilityResource(): string
    {
        return 'orders';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where($this->qualifyColumn('team_id'), $user->team_id)
                ->orWhereHas('owner', fn (Builder $o) => $o->where('team_id', $user->team_id));
        });
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('owner_id'), $user->id);
    }
}
