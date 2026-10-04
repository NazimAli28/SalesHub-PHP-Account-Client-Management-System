<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) helpers: secrets, QR codes, code verification with replay protection
 * and single-use recovery codes. Never log or return secrets outside the setup response.
 */
final class TwoFactorAuthenticator
{
    public const ISSUER = 'SalesHub';

    public const RECOVERY_CODE_COUNT = 8;

    /** Accept one 30-second step either side of now (clock drift). */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $engine = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(self::ISSUER, $user->email, $secret);
    }

    /**
     * The otpauth URL rendered as an SVG QR code, as a data URI the SPA can put in an <img>.
     */
    public function qrCodeDataUri(string $otpauthUrl): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))
            ->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Verify a 6-digit code. A timestep that was already accepted for this user is rejected,
     * so an intercepted code cannot be replayed within its validity window.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = $this->timestepCacheKey($user);
        $lastTimestep = (int) Cache::get($key, 0);

        $timestep = $this->engine->verifyKeyNewer($secret, $code, $lastTimestep, self::WINDOW);

        if (! is_int($timestep)) {
            return false;
        }

        // Kept a little longer than the acceptance window (3 steps x 30 s).
        Cache::put($key, $timestep, now()->addMinutes(5));

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * Remove the matching recovery code from the user's list. Returns false when none matches.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = Str::upper(trim($code));

        /** @var list<string> $codes */
        $codes = array_values((array) ($user->two_factor_recovery_codes ?? []));

        foreach ($codes as $index => $candidate) {
            if (hash_equals($candidate, $normalized)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function forgetTimestep(User $user): void
    {
        Cache::forget($this->timestepCacheKey($user));
    }

    private function timestepCacheKey(User $user): string
    {
        return 'two-factor-timestep:'.$user->getKey();
    }
}
