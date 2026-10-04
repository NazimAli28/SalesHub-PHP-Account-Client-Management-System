<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\IpAllowlist;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * bcrypt hash of a throwaway string, checked when the identifier is unknown so that
     * response time does not reveal whether an account exists.
     */
    private const DUMMY_HASH = '$2y$12$1moCwaSVy3yBMKsSI9wsU.woXJUhzR/siqiKJ7Yw74dmlwt9VT/SW';

    public function __invoke(LoginRequest $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Sign-in requires a browser session request.');

        $identifier = $request->identifier();
        $password = $request->string('password')->toString();

        /** @var User|null $user */
        $user = User::query()
            ->where(DB::raw('lower('.$request->identifierField().')'), $identifier)
            ->first();

        $passwordMatches = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if ($user === null || ! $passwordMatches) {
            AuditLogger::auth('login_failed', $user, $request, ['identifier' => $identifier]);

            $message = 'These credentials do not match our records.';

            return response()->json(['message' => $message, 'errors' => ['login' => [$message]]], 422);
        }

        if (! $user->is_active) {
            AuditLogger::auth('login_blocked_inactive', $user, $request);

            return response()->json([
                'message' => 'This account has been deactivated. Contact an administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        if (! IpAllowlist::permits($user, $request->ip())) {
            AuditLogger::auth('login_blocked_ip', $user, $request);

            return response()->json([
                'message' => 'Sign-in is only allowed from the office network.',
                'code' => 'ip_not_allowed',
            ], 403);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        LoginThrottle::clear($identifier, $request->ip());

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();

        AuditLogger::auth('login', $user, $request);

        return (new MeResource($user->load(['team', 'workstation', 'roles'])))->response();
    }
}
