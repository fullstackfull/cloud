<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the platform's security headers to every response.
 *
 * Registered globally rather than on the API group, because Laravel sorts
 * middleware by priority: authentication runs before a group's appended
 * middleware, so an appended header middleware would silently miss every 401 —
 * exactly the responses an attacker probes most.
 *
 * API and webhook responses get a deny-everything Content-Security-Policy:
 * nothing in a JSON response is meant to be rendered, and a permissive policy
 * on JSON endpoints is a known vector for content sniffing and reflected-file
 * download. Other paths (the Horizon dashboard, for instance) are served a
 * policy that still permits their own assets.
 */
final class SecurityHeaders
{
    private const string API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    private const string DASHBOARD_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $isApi = $request->is('api/*', 'webhooks/*');

        $headers = [
            'Content-Security-Policy' => $isApi ? self::API_CSP : self::DASHBOARD_CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
        ];

        if ($isApi) {
            // API responses may contain invoices, IP allocations and service
            // details; never let a shared cache keep them.
            $headers['Cache-Control'] = 'no-store, private';
        }

        if (config('security.force_https')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
