<?php

namespace App\Analytics;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Which people's records an analytics request may aggregate: the broadest `reports.view-*` tier the user holds,
 * narrowed by the optional team / user filter. `userIds` null means unrestricted.
 */
final class AnalyticsScope
{
    /**
     * @param  'all'|'team'|'own'  $tier
     * @param  list<int>|null  $userIds
     */
    public function __construct(
        public readonly User $user,
        public readonly string $tier,
        public readonly ?array $userIds,
        public readonly ?int $teamId,
        public readonly ?int $userId,
    ) {}

    /**
     * @throws AuthorizationException when the filter reaches outside the user's tier
     */
    public static function resolve(User $user, ?int $teamId, ?int $userId): self
    {
        $tier = match (true) {
            $user->can('reports.view-all') => 'all',
            $user->can('reports.view-team') => 'team',
            $user->can('reports.view-own') => 'own',
            default => throw new AuthorizationException,
        };

        if ($tier === 'own') {
            if ($userId !== null && $userId !== $user->id) {
                throw new AuthorizationException;
            }

            return new self($user, $tier, [$user->id], null, $userId);
        }

        if ($tier === 'team') {
            if ($user->team_id === null || ($teamId !== null && $teamId !== $user->team_id)) {
                throw new AuthorizationException;
            }

            $teamId = $user->team_id;
        }

        if ($userId !== null) {
            $member = User::query()->whereKey($userId)->value('team_id');

            if ($tier === 'team' && $member !== $teamId) {
                throw new AuthorizationException;
            }

            return new self($user, $tier, [$userId], $teamId, $userId);
        }

        $ids = $teamId === null
            ? null
            : User::query()->where('team_id', $teamId)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return new self($user, $tier, $ids, $teamId, null);
    }

    /**
     * Leaderboards compare agents, so they only make sense across more than one person.
     */
    public function showsLeaderboard(): bool
    {
        return $this->tier !== 'own' && $this->userId === null;
    }
}
