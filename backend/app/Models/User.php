<?php

namespace App\Models;

use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'username', 'email', 'password', 'team_id', 'workstation_id', 'avatar_path', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasApprovals, HasFactory, HasRoles, HasVisibilityScope, LogsModelActivity, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Workstation, $this>
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * @return HasOne<Team, $this>
     */
    public function ledTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'team_lead_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'owner_id');
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'owner_id');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'requested_by_id');
    }

    protected static function visibilityResource(): string
    {
        return 'users';
    }

    /**
     * Users have no view-all/view-team/view-own permissions: `users.view` plus a management
     * permission (`users.update`) sees everyone, `users.view` alone sees the own team, and
     * everyone else only sees themselves.
     *
     * @return 'all'|'team'|'own'|'none'
     */
    protected function visibilityTier(User $user): string
    {
        return match (true) {
            $user->can('users.view') && $user->can('users.update') => 'all',
            $user->can('users.view') => 'team',
            default => 'own',
        };
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('team_id'), $user->team_id);
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->whereKey($user->getKey());
    }
}
