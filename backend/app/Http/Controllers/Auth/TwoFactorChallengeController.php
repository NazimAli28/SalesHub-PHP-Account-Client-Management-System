<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Support\AuditLogger;
use App\Support\IpAllowlist;
use App\Support\LoginThrottle;
use App\Support\TwoFactorAuthenticator;
use App\Support\TwoFactorPendingLogin;
use Illuminate\Http\JsonResponse;

/**
 * Second sign-in step for users with two-factor authentication: a TOTP code or a single-use recovery code.
 */
class TwoFactorChallengeController extends Controller
{
    public function __invoke(
        TwoFactorChallengeRequest $request,
        TwoFactorAuthenticator $twoFactor,
        CompleteLogin $completeLogin,
    ): JsonResponse {
        abort_unless($request->hasSession(), 400, 'Sign-in requires a browser session request.');

        $user = TwoFactorPendingLogin::user($request);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            TwoFactorPendingLogin::forget($request);

            return response()->json([
                'message' => 'Your sign-in attempt has expired. Please sign in again.',
                'code' => 'two_factor_expired',
            ], 422);
        }

        if (! $user->is_active || ! IpAllowlist::permits($user, $request->ip())) {
            TwoFactorPendingLogin::forget($request);
            AuditLogger::auth($user->is_active ? 'login_blocked_ip' : 'login_blocked_inactive', $user, $request);

            return response()->json([
                'message' => $user->is_active
                    ? 'Sign-in is only allowed from the office network.'
                    : 'This account has been deactivated. Contact an administrator.',
                'code' => $user->is_active ? 'ip_not_allowed' : 'account_inactive',
            ], 403);
        }

        $retryAfter = LoginThrottle::twoFactorLockedOutFor($user->getKey(), $request->ip());

        if ($retryAfter !== null) {
            return LoginThrottle::twoFactorLockedOutResponse($retryAfter);
        }

        $usesRecoveryCode = $request->usesRecoveryCode();
        $secret = (string) $user->two_factor_secret;

        $valid = $usesRecoveryCode
            ? $twoFactor->consumeRecoveryCode($user, $request->string('recovery_code')->toString())
            : $twoFactor->verify($user, $secret, $request->string('code')->toString());

        if (! $valid) {
            LoginThrottle::twoFactorFailed($user->getKey(), $request->ip());
            AuditLogger::auth('two_factor_failed', $user, $request, [
                'method' => $usesRecoveryCode ? 'recovery_code' : 'totp',
            ]);

            $field = $usesRecoveryCode ? 'recovery_code' : 'code';
            $message = $usesRecoveryCode
                ? 'This recovery code is invalid or has already been used.'
                : 'This code is invalid or has already been used.';

            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        $remember = TwoFactorPendingLogin::remember($request);
        TwoFactorPendingLogin::forget($request);
        LoginThrottle::twoFactorClear($user->getKey(), $request->ip());

        if ($usesRecoveryCode) {
            AuditLogger::auth('two_factor_recovery_code_used', $user, $request, [
                'remaining' => count((array) ($user->two_factor_recovery_codes ?? [])),
            ]);
        }

        return $completeLogin->handle($request, $user, $remember, [
            'two_factor' => $usesRecoveryCode ? 'recovery_code' : 'totp',
        ]);
    }
}
