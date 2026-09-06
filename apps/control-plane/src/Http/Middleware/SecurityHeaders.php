<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the platform's security headers.
 *
 * The API serves JSON to a separate SPA origin, so its Content-Security-Policy
 * is maximally restrictive: nothing is meant to be rendered from an API
 * response, and a permissive policy on JSON endpoints is a well-known vector
 * for content-sniffing and reflected-file-download attacks.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            // API responses are never a document; deny everything.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
            // Responses may contain invoices, IP allocations and service
            // details; never let a shared cache keep them.
            'Cache-Control' => 'no-store, private',
        ];

        if (config('security.force_https')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
