<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes auth and security entries to the activity log (data-model section 7).
 * Never pass passwords or secret values in $extra.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function auth(string $event, ?User $user, Request $request, array $extra = []): void
    {
        $logger = activity('auth')
            ->event($event)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                ...$extra,
            ]);

        if ($user !== null) {
            $logger->performedOn($user)->causedBy($user);
        }

        $logger->log($event);
    }

    /**
     * @param  list<string>  $fields
     */
    public static function credentialsRevealed(Model $subject, User $user, Request $request, array $fields): void
    {
        activity('security')
            ->event('credentials_revealed')
            ->performedOn($subject)
            ->causedBy($user)
            ->withProperties(['fields' => $fields, 'ip' => $request->ip()])
            ->log('credentials_revealed');
    }
}
