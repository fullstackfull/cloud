<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Throwable;

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

        /*
         * Status reads a customer makes while waiting.
         *
         * Wave 4 gave the portal three endpoints it refreshes rather than
         * submits to: an operation's status, the account overview and the
         * activity feed. Left on the mutation limiter they would answer 429 to
         * a customer who is doing nothing but watching a reboot they already
         * asked for once — the limiter would be punishing observation, and the
         * screen would report a refresh failure for a machine that is fine.
         *
         * Roomier than `provisioning` and deliberately still bounded. The
         * ceiling is sized from what one open portal can actually generate:
         * the client observes one operation at a time, with a floor of a few
         * seconds between reads and no polling of hidden tabs, so a legitimate
         * session sits far below this. What it stops is a client that has lost
         * its backoff and is reading in a loop.
         *
         * Keyed per user, not per IP: an office behind one address is many
         * customers, and one of them watching a rebuild must not spend the
         * others' budget.
         */
        RateLimiter::for('reads', function (Request $request): Limit {
            $user = $request->user();
            $limit = (int) config('security.rate_limits.reads.attempts', 300);

            return $user instanceof User
                ? Limit::perMinute($limit)->by('reads:'.$user->id)
                : Limit::perMinute(30)->by($request->ip() ?? 'unknown');
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
        /*
         * Preflight, bounded by how expensive the answer is to produce.
         *
         * A simulation run touches this platform's own database and its
         * controlled providers, so it is cheap and an operator iterating on a
         * configuration should not be fought. A read-only-real run sends
         * requests to somebody else's API for every provider in scope, and an
         * operator clicking a button twenty times would become a burst at a
         * hypervisor that has customers on it. The limit protects the
         * providers, not this platform — which is why it is keyed on the
         * caller and the mode rather than on the route.
         */
        RateLimiter::for('preflight', function (Request $request): Limit {
            $user = $request->user();
            $real = $request->input('mode') === 'read_only_real';

            $limit = $real
                ? (int) config('security.rate_limits.preflight.real_attempts', 6)
                : (int) config('security.rate_limits.preflight.simulation_attempts', 60);

            return $user instanceof User
                ? Limit::perMinute($limit)->by(sprintf('preflight:%s:%s', $real ? 'real' : 'sim', $user->id))
                : Limit::perMinute(3)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(300)
            ->by($request->ip() ?? 'unknown'));

        /*
         * Sending invitations is bounded per account, not per user.
         *
         * The thing being spent is somebody else's inbox: an account that can
         * invite as fast as it can POST is a mailing list with Lynomia's
         * reputation attached, and two administrators of the same account
         * sharing one bucket is the correct arrangement — the limit protects
         * the recipients, and the recipients do not care which colleague sent
         * it.
         *
         * Keyed on the account the middleware resolved, never on the
         * `X-Lynomia-Customer` header. Both invitation routes attach this
         * limiter through `ThrottleAfterAccountResolution`, declared after
         * `customer`, so the account is already settled by the time this
         * closure runs. The plain `throttle:team-invitations` alias did NOT
         * guarantee that: the router's priority sort runs every
         * ThrottleRequests before `ResolveActingCustomer`, this closure found
         * no account, and the budget became one per administrator rather
         * than one per account. TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest
         * pins the attachment and the order; the key is pinned separately.
         *
         * The header used to be consulted first, with a fallback that fired
         * only when it was ABSENT. A caller who sent junk therefore still
         * acted on their own account — the resolver treats an unparseable
         * value as absent — while presenting a bucket key nobody had ever
         * used. That is not a rotated limit but an unbounded one: every
         * distinct value is a fresh budget, and the same held for merely
         * case-folding the caller's own id, since Crockford base32 is
         * case-insensitive and the resolver lower-cases it while the limiter
         * did not. This bucket is the whole control — `ResendInvitation`
         * writes `sent_count` and `last_sent_at` and compares neither, so it
         * has no cooldown of its own.
         *
         * Falling back to the user, and then to the address, keeps the limiter
         * defined for a request that somehow arrives without an account
         * resolved — a route in the wrong middleware group, or this limiter
         * attached through the plain `throttle:` alias, both wiring mistakes
         * rather than something a caller can arrange.
         */
        RateLimiter::for('team-invitations', function (Request $request): Limit {
            $user = $request->user();

            try {
                $account = 'account:'.app(ActingCustomer::class)->id();
            } catch (Throwable) {
                $account = $user instanceof User ? 'user:'.$user->id : null;
            }

            return Limit::perHour((int) config('security.rate_limits.team_invitations.attempts', 30))
                ->by('invite:'.($account ?? $request->ip() ?? 'unknown'));
        });

        /*
         * Redeeming one is bounded per caller, and tightly.
         *
         * A token is 64 hex characters, so guessing is not the threat model —
         * but an authenticated caller trying tokens in a loop is cheap to
         * write and would otherwise be free, and the endpoint answers
         * differently for a token that names a live offer than for one that
         * names nothing. The limit is what turns that difference into
         * something nobody can measure at scale.
         */
        RateLimiter::for('invitations', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute((int) config('security.rate_limits.invitations.attempts', 10))
                ->by($user instanceof User ? 'invitation:'.$user->id : ($request->ip() ?? 'unknown'));
        });
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
