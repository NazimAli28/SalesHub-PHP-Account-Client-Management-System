<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * Activity entries for user-management events (log name `user`). Never pass password values.
 */
final class UserAudit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, User $actor, User $subject, array $properties = []): void
    {
        activity('user')
            ->event($event)
            ->performedOn($subject)
            ->causedBy($actor)
            ->withProperties($properties)
            ->log($event);
    }
}
