<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the metrics endpoint with a bearer token.
 *
 * **These metrics are sensitive and the endpoint is never public.** Together
 * they disclose how many customers the platform has and what they buy
 * (`lynomia_services_active`, `lynomia_orders_total`), how much headroom is
 * left before orders start failing (`lynomia_node_capacity_ratio`,
 * `lynomia_ip_pool_runway_days`), and what the business earns
 * (`lynomia_mrr_minor`). That is a competitor's market research and an
 * attacker's capacity plan in a single unauthenticated GET — and unlike a data
 * breach, nothing about serving it looks wrong in any log.
 *
 * Three decisions are worth explaining:
 *
 *  1. **An unset token disables the endpoint rather than opening it.** The
 *     failure mode of "no token configured means no authentication" is a fresh
 *     deployment that publishes revenue to the internet, and it is silent.
 *
 *  2. **Rejection is 404, not 401.** A 401 confirms that something exists at
 *     this path and invites a scanner to come back. The operator's signal is
 *     the warning logged here, which is where they should be looking anyway;
 *     an unauthenticated prober gets nothing at all.
 *
 *  3. **The comparison is over digests, not the tokens themselves.**
 *     hash_equals is constant-time only across equal-length inputs; given
 *     different lengths it returns immediately, which leaks the length of the
 *     real token. Hashing both to a fixed 64 characters first removes that,
 *     and costs one SHA-256 of a short string per scrape.
 */
final class MetricsTokenGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('monitoring.metrics.enabled', false)) {
            $this->refuse($request, 'metrics_disabled');
        }

        $expected = config('monitoring.metrics.token');

        if (! is_string($expected) || $expected === '') {
            $this->refuse($request, 'no_token_configured');
        }

        $presented = $request->bearerToken();

        if (! is_string($presented) || $presented === '') {
            $this->refuse($request, 'missing_token');
        }

        /** @var string $expected */
        /** @var string $presented */
        if (! hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
            $this->refuse($request, 'token_mismatch');
        }

        return $next($request);
    }

    private function refuse(Request $request, string $reason): never
    {
        /*
         * The reason and the source, never the token — not even a prefix of it.
         * A rejected credential in a log is still a credential in a log, and
         * the one that gets rejected today is often the one that is valid
         * somewhere else.
         */
        Log::warning('Refused a request to the metrics endpoint.', [
            'reason' => $reason,
            'source_ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        abort(404);
    }
}
