<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * How much roomier the per-IP ceiling is than the per-identity bucket.
     *
     * It has to sit well above what a shared egress address (an office NAT, a
     * carrier CGNAT) produces legitimately, or the ceiling becomes a way to
     * deny sign-in to everyone behind it — while still being far below the
     * "unlimited" that keying on a caller-chosen address currently permits.
     */
    private const int PER_IP_MULTIPLIER = 6;

    public function boot(): void
    {
        /*
         * Authentication limiters return two limits, not one.
         *
         * The tight bucket keys on the submitted identity *and* the source IP:
         * keying on the identity alone would let anyone lock a known customer
         * out by hammering their address. But that bucket alone bounds nothing
         * for a host that varies the address — every new address is a fresh
         * bucket — so a second, deliberately roomier bucket keyed on the source
         * IP alone caps how much unauthenticated auth traffic one host may
         * generate across *all* identities. Password spraying, credential
         * stuffing and bulk registration all live in that gap.
         */
        $this->defineAuthLimiter('login', 'login');
        $this->defineAuthLimiter('register', 'register');
        $this->defineAuthLimiter('password-reset', 'password_reset');
        $this->defineAuthLimiter('two-factor', 'two_factor');

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            if (! $user instanceof User) {
                return Limit::perMinute(30)->by($request->ip() ?? 'unknown');
            }

            // A personal access token may carry its own ceiling; otherwise the
            // configured default applies.
            $token = $user->currentAccessToken();
            $perMinute = isset($token->rate_limit_per_minute)
                ? (int) $token->rate_limit_per_minute
                : (int) config('security.rate_limits.api_token.attempts', 120);

            return Limit::perMinute($perMinute)->by('user:'.$user->id);
        });

        RateLimiter::for('provisioning', function (Request $request): Limit {
            $user = $request->user();
            $limit = (int) config('security.rate_limits.provisioning.attempts', 20);

            return $user instanceof User
                ? Limit::perMinute($limit)->by('provisioning:'.$user->id)
                : Limit::perMinute(5)->by($request->ip() ?? 'unknown');
        });

        /*
         * Webhook endpoints are throttled generously and per source address:
         * a payment provider legitimately bursts, and dropping a webhook costs
         * a reconciliation cycle. Signature verification, not rate limiting, is
         * the security control here.
         */
        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(300)
            ->by($request->ip() ?? 'unknown'));
    }

    private function defineAuthLimiter(string $name, string $configKey): void
    {
        $attempts = (int) config("security.rate_limits.{$configKey}.attempts", 5);
        $decay = (int) config("security.rate_limits.{$configKey}.decay_minutes", 1);
        $perIpAttempts = (int) config(
            "security.rate_limits.{$configKey}.per_ip_attempts",
            $attempts * self::PER_IP_MULTIPLIER,
        );

        RateLimiter::for($name, static function (Request $request) use ($name, $attempts, $decay, $perIpAttempts): array {
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinutes($decay, $attempts)
                    ->by($name.'|'.self::identityFor($request).'|'.$ip),

                Limit::perMinutes($decay, $perIpAttempts)->by($name.'|ip|'.$ip),
            ];
        });
    }

    /**
     * The subject a limiter bucket belongs to.
     *
     * Most auth endpoints carry the address. The two-factor challenge does not
     * — it is submitted with a challenge token and a code, and nothing else —
     * so keying it on `email` would collapse every user's second-factor
     * attempts into one shared per-IP bucket: one visitor behind an office NAT
     * could stop everyone else there from completing sign-in. The challenge
     * token identifies the attempt precisely, and is hashed so a limiter key
     * never holds a live credential.
     */
    private static function identityFor(Request $request): string
    {
        $challenge = $request->input('challenge_token');

        if (is_string($challenge) && $challenge !== '') {
            return 'challenge:'.hash('sha256', $challenge);
        }

        $email = $request->input('email');

        /*
         * Read defensively: a limiter closure runs inside ThrottleRequests
         * BEFORE any validation, so it sees whatever shape the caller sent.
         * Casting an array to string raises a PHP warning that the framework's
         * error handler turns into a 500 thrown before RateLimiter::attempt()
         * — a request shape that always fails and is never counted.
         */
        return is_string($email) ? strtolower(trim($email)) : 'non-string';
    }
}
