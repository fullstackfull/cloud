<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation ID to every request and puts it in the logging context.
 *
 * The ID flows from the HTTP request into every log line, every queued job and
 * every provisioning attempt that request causes, so that "customer says their
 * VPS order failed at 14:02" becomes a single grep rather than an archaeology
 * exercise across three services.
 *
 * A client-supplied X-Request-Id is honoured only when it looks like an
 * identifier, so a caller cannot inject newlines or arbitrary text into logs.
 */
final class AssignRequestId
{
    private const string HEADER = 'X-Request-Id';

    private const int MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        $request->headers->set(self::HEADER, $requestId);

        Context::add('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $supplied = $request->headers->get(self::HEADER);

        if (is_string($supplied)
            && $supplied !== ''
            && strlen($supplied) <= self::MAX_LENGTH
            && preg_match('/\A[A-Za-z0-9._-]+\z/', $supplied) === 1
        ) {
            return $supplied;
        }

        return (string) Str::ulid();
    }
}
