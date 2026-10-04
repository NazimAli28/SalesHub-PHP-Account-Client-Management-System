<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role-based row visibility: the broadest of `{resource}.view-all`, `.view-team`
 * and `.view-own` that the user holds decides the scope.
 */
trait HasVisibilityScope
{
    /**
     * Permission prefix, e.g. "leads" for leads.view-all.
     */
    abstract protected static function visibilityResource(): string;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract protected function applyTeamVisibility(Builder $query, User $user): Builder;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract protected function applyOwnVisibility(Builder $query, User $user): Builder;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($this->visibilityTier($user)) {
            'all' => $query,
            'team' => $user->team_id === null
                ? $query->whereRaw('1 = 0')
                : $this->applyTeamVisibility($query, $user),
            'own' => $this->applyOwnVisibility($query, $user),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return 'all'|'team'|'own'|'none'
     */
    protected function visibilityTier(User $user): string
    {
        $resource = static::visibilityResource();

        return match (true) {
            $user->can($resource.'.view-all') => 'all',
            $user->can($resource.'.view-team') => 'team',
            $user->can($resource.'.view-own') => 'own',
            default => 'none',
        };
    }
}
