<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\EnsureNotDemoMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\LoginThrottle;
use App\Support\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Setting up, confirming and turning off TOTP two-factor sign-in for the signed-in user.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    /**
     * POST /api/auth/two-factor: start (or restart) setup with a new secret. Not active until confirmed.
     */
    public function store(Request $request): JsonResponse
    {
        EnsureNotDemoMode::check('two-factor sign-in');
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return $this->conflict('Two-factor sign-in is already on.', 'two_factor_already_enabled');
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->twoFactor->forgetTimestep($user);

        $otpauthUrl = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code' => $this->twoFactor->qrCodeDataUri($otpauthUrl),
        ]]);
    }

    /**
     * POST /api/auth/two-factor/confirm: prove the authenticator works; returns the recovery codes once.
     */
    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        EnsureNotDemoMode::check('two-factor sign-in');
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return $this->conflict('Two-factor sign-in is already on.', 'two_factor_already_enabled');
        }

        if ($user->two_factor_secret === null) {
            return $this->conflict('Start two-factor setup first.', 'two_factor_not_started');
        }

        if (! $this->twoFactor->verify($user, (string) $user->two_factor_secret, $request->string('code')->toString())) {
            $message = 'This code is invalid or has already been used.';

            return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLogger::auth('two_factor_enabled', $user, $request);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /**
     * DELETE /api/auth/two-factor {password, code}: turn two-factor sign-in off (also cancels an
     * unfinished setup). While it is on, `code` must be a current authenticator code or an unused
     * recovery code (which is spent); wrong codes count toward the two-factor lockout.
     */
    public function destroy(DisableTwoFactorRequest $request): Response|JsonResponse
    {
        $user = $this->user($request);
        $wasEnabled = $user->hasTwoFactorEnabled();

        if ($wasEnabled) {
            $retryAfter = LoginThrottle::twoFactorLockedOutFor($user->getKey(), $request->ip());

            if ($retryAfter !== null) {
                return LoginThrottle::twoFactorLockedOutResponse($retryAfter);
            }

            $code = $request->string('code')->toString();
            $valid = preg_match('/^\s*\d{3}\s*\d{3}\s*$/', $code) === 1
                ? $this->twoFactor->verify($user, (string) $user->two_factor_secret, $code)
                : $this->twoFactor->consumeRecoveryCode($user, $code);

            if (! $valid) {
                LoginThrottle::twoFactorFailed($user->getKey(), $request->ip());
                AuditLogger::auth('two_factor_failed', $user, $request, ['action' => 'disable']);

                $message = 'This code is invalid or has already been used.';

                return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
            }

            LoginThrottle::twoFactorClear($user->getKey(), $request->ip());
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($wasEnabled) {
            AuditLogger::auth('two_factor_disabled', $user, $request);
        }

        return response()->noContent();
    }

    /**
     * POST /api/auth/two-factor/recovery-codes/view {password}: show the unused recovery codes.
     */
    public function recoveryCodes(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->hasTwoFactorEnabled()) {
            return $this->conflict('Two-factor sign-in is off.', 'two_factor_not_enabled');
        }

        AuditLogger::auth('two_factor_recovery_codes_viewed', $user, $request);

        return response()->json(['data' => [
            'recovery_codes' => array_values((array) ($user->two_factor_recovery_codes ?? [])),
        ]]);
    }

    /**
     * POST /api/auth/two-factor/recovery-codes {password}: replace every recovery code with a new set.
     */
    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->hasTwoFactorEnabled()) {
            return $this->conflict('Two-factor sign-in is off.', 'two_factor_not_enabled');
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        AuditLogger::auth('two_factor_recovery_codes_regenerated', $user, $request);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }

    private function conflict(string $message, string $code): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 409);
    }
}
