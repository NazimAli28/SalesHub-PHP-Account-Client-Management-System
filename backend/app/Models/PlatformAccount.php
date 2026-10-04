<?php

namespace App\Models;

use App\Enums\AccountStanding;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\PlatformAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'email', 'email_password', 'discord_email', 'discord_username', 'discord_password',
    'discord_created_on', 'recovery_email', 'recovery_phone', 'phone_holder_name', 'batch_date',
    'workstation_id', 'assigned_at', 'standing', 'standing_changed_at', 'notes',
])]
#[Hidden(['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'])]
class PlatformAccount extends Model
{
    /** @use HasFactory<PlatformAccountFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_password' => 'encrypted',
            'discord_password' => 'encrypted',
            'recovery_phone' => 'encrypted',
            'phone_holder_name' => 'encrypted',
            'standing' => AccountStanding::class,
            'discord_created_on' => 'date:Y-m-d',
            'batch_date' => 'date:Y-m-d',
            'assigned_at' => 'datetime',
            'standing_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workstation, $this>
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
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
     * Adds a boolean `has_client` attribute (v1 accounts.has_client).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithHasClient(Builder $query): void
    {
        $query->withExists('orders as has_client');
    }

    protected static function visibilityResource(): string
    {
        return 'platform-accounts';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('workstation', fn (Builder $q) => $q->where('team_id', $user->team_id));
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        if ($user->workstation_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('workstation_id'), $user->workstation_id);
    }
}
