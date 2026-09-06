<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment, verification and recovery-code handling.
 *
 * Recovery codes are stored hashed, exactly like passwords: a database dump
 * must not hand an attacker a working bypass of the second factor. They are
 * returned in clear exactly once, at the moment they are generated.
 */
final readonly class ManageTwoFactor
{
    public const int RECOVERY_CODE_COUNT = 8;

    /**
     * Window of 1 accepts the previous and next 30-second step, tolerating
     * modest clock drift on the customer's device without meaningfully
     * widening the guessing surface.
     */
    private const int VERIFICATION_WINDOW = 1;

    public function __construct(
        private Google2FA $google2fa,
    ) {}

    /**
     * Begin enrolment. The secret is stored but not yet confirmed, so the
     * account is not protected — and cannot be locked out — until the customer
     * proves they can generate a valid code.
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function beginEnrolment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl(
                (string) config('app.name'),
                $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Confirm enrolment with a code from the authenticator app.
     *
     * @return list<string>|null the recovery codes, in clear, exactly once
     */
    public function confirmEnrolment(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        if (! $this->verifyTotp($user->two_factor_secret, $code)) {
            return null;
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Verify a second factor at sign-in: either a TOTP code, or a recovery
     * code, which is consumed on use.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        if ($this->verifyTotp((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($codes),
        ])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/\A\d{6}\z/', $code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, self::VERIFICATION_WINDOW);
    }

    private function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $stored = $user->two_factor_recovery_codes ?? [];
        $candidate = strtolower(trim($candidate));

        foreach ($stored as $index => $hash) {
            if (Hash::check($candidate, $hash)) {
                // Single use: remove it before returning, so a code captured in
                // transit cannot be replayed.
                unset($stored[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($stored),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => strtolower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => Hash::make($code), $codes);
    }
}
