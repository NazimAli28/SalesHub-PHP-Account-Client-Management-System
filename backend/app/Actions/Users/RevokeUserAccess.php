<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeUserAccess
{
    /**
     * Ends every browser session and API token of the user. `$exceptSessionId` keeps one session alive.
     */
    public function handle(User $user, ?string $exceptSessionId = null): void
    {
        $sessions = DB::table('sessions')->where('user_id', $user->getKey());

        if ($exceptSessionId !== null) {
            $sessions->where('id', '!=', $exceptSessionId);
        }

        $sessions->delete();
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => null])->saveQuietly();
    }
}
