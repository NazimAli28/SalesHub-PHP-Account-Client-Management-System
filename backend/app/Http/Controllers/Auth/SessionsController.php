<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\DescribeUserAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The signed-in user's browser sessions (rows of the `sessions` table; database session driver).
 */
class SessionsController extends Controller
{
    /**
     * GET /api/auth/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $currentId = $request->session()->getId();

        if (! $this->usesDatabaseSessions()) {
            // Other drivers cannot list sessions; show just this one.
            return response()->json(['data' => [[
                'id' => $this->opaqueId($currentId),
                'ip_address' => $request->ip(),
                'device' => DescribeUserAgent::label($request->userAgent()),
                'last_active_at' => now()->toIso8601ZuluString(),
                'is_current' => true,
            ]]]);
        }

        $rows = DB::table($this->table())
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = $rows
            ->map(fn (object $row): array => [
                'id' => $this->opaqueId((string) $row->id),
                'ip_address' => $row->ip_address,
                'device' => DescribeUserAgent::label($row->user_agent),
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $row->last_activity)->toIso8601ZuluString(),
                'is_current' => hash_equals((string) $row->id, $currentId),
            ])
            ->sortByDesc('is_current')
            ->values()
            ->all();

        return response()->json(['data' => $sessions]);
    }

    /**
     * DELETE /api/auth/sessions/others {password}: sign out every other browser.
     */
    public function destroyOthers(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $revoked = 0;

        if ($this->usesDatabaseSessions()) {
            $revoked = DB::table($this->table())
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        // Invalidate "remember me" cookies held by other browsers too.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        AuditLogger::auth('sessions_revoked', $user, $request, ['count' => $revoked]);

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    private function table(): string
    {
        return (string) (config('session.table') ?: 'sessions');
    }

    /**
     * Session IDs are bearer secrets, so the API only exposes a keyed hash of them.
     */
    private function opaqueId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 24);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }
}
