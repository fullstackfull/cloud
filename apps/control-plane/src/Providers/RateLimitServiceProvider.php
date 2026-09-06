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
    public function boot(): void
    {
        /*
         * Authentication limiters key on address *and* source IP together.
         *
         * Keying on the address alone would let anyone lock a known customer
         * out by hammering their address; keying on IP alone would let a single
         * host spray many addresses. Requiring both to match bounds each attack
         * without enabling the other.
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
            $perMinute = is_object($token) && isset($token->rate_limit_per_minute) && $token->rate_limit_per_minute !== null
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

        RateLimiter::for($name, static function (Request $request) use ($attempts, $decay): Limit {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinutes($decay, $attempts)
                ->by($email.'|'.($request->ip() ?? 'unknown'));
        });
    }
}
