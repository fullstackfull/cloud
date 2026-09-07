<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Lynomia\Http\Middleware\AssignRequestId;
use Lynomia\Http\Middleware\ResolveActingCustomer;
use Lynomia\Http\Middleware\SecurityHeaders;
use Lynomia\Http\Responses\ApiError;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/v1')
                ->as('api.v1.')
                ->group(base_path('routes/api_v1.php'));

            Route::middleware('api')
                ->prefix('api/admin')
                ->as('api.admin.')
                ->group(base_path('routes/api_admin.php'));

            // Provider webhooks are deliberately outside the versioned API:
            // their shape is dictated by the provider, they are unauthenticated
            // in the session sense, and they must never be subject to the same
            // CSRF or session middleware as the portals.
            Route::middleware('webhook')
                ->prefix('webhooks')
                ->as('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        /*
         * Both of these are global rather than group middleware.
         *
         * Laravel's middleware priority runs authentication before a group's
         * appended middleware, so an appended header middleware would miss
         * every 401 — the responses an attacker probes most. And a request that
         * matches no route never enters a group at all, so a correlation id
         * assigned there would be absent from exactly the 404 a confused
         * customer is most likely to quote to support.
         */
        $middleware->prepend([
            AssignRequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->group('webhook', [
            'throttle:webhooks',
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,

            // Resolves the one customer account a request acts for. Every
            // customer-scoped route carries it, and every customer-scoped
            // query reads the answer from it rather than from the request.
            'customer' => ResolveActingCustomer::class,
        ]);

        // Never trust proxy headers blindly. The production Ansible role sets
        // TRUSTED_PROXIES to the actual load balancer addresses; a wildcard
        // would let any client spoof its source IP and defeat rate limiting.
        $middleware->trustProxies(
            at: array_values(array_filter(
                array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))
            )) ?: null,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );

        /*
         * One JSON error shape for the whole API.
         *
         * Machine-readable codes let the SPA and customer integrations branch
         * on the cause without matching on prose, and keep the wording free to
         * change or be localised.
         */
        $exceptions->render(static function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*', 'webhooks/*') && ! $request->expectsJson()) {
                return null;
            }

            $error = match (true) {
                $e instanceof DomainException => ApiError::make(
                    $e->errorCode(),
                    $e->getMessage(),
                    $e->httpStatus(),
                    $e->context(),
                ),

                $e instanceof ValidationException => ApiError::make(
                    'validation.failed',
                    'The submitted data is invalid.',
                    422,
                    ['fields' => $e->errors()],
                ),

                $e instanceof AuthenticationException => ApiError::make(
                    'auth.unauthenticated',
                    'Authentication is required.',
                    401,
                ),

                /*
                 * Both forms, because only one of them ever arrives here.
                 *
                 * Laravel converts an AuthorizationException into an
                 * AccessDeniedHttpException before the renderer runs, so the
                 * first arm below is unreachable through the normal path and
                 * the exception used to fall through to the generic
                 * `http.403` - a different code from the `auth.forbidden` the
                 * API documents, for the same event. Nine modules independently
                 * worked around that by inventing their own DomainException
                 * rather than using the framework's, which is a strong signal
                 * that the renderer, not the modules, was wrong.
                 *
                 * The first arm stays for a caller that throws it directly.
                 * The third catches `abort(403)`, which produces a plain
                 * HttpException: a client branches on the code, and it has no
                 * way to know - and no reason to care - which of the three
                 * mechanisms inside the application refused it.
                 */
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException,
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 => ApiError::make(
                    'auth.forbidden',
                    'You are not permitted to perform this action.',
                    403,
                ),

                $e instanceof TokenMismatchException => ApiError::make(
                    'auth.csrf_token_mismatch',
                    'The CSRF token is missing or stale. Refresh and try again.',
                    419,
                ),

                // Never disclose which model or id was missing: on an API that
                // exposes ULIDs, the difference between 403 and 404 is itself
                // an enumeration oracle.
                // RouteNotFoundException is deliberately NOT mapped here.
                // It means the application asked for a route that does not
                // exist — a bug in link generation, not a missing resource —
                // and folding it into 404 hides exactly the kind of broken
                // notification link that only shows up in production.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiError::make(
                    'resource.not_found',
                    'The requested resource does not exist.',
                    404,
                ),

                $e instanceof HttpExceptionInterface => ApiError::make(
                    'http.'.$e->getStatusCode(),
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode(),
                ),

                default => null,
            };

            if ($error !== null) {
                return $error->toResponse($request);
            }

            // Unhandled: report it, and give the client a correlation ID rather
            // than a stack trace. The details live in the structured log.
            return ApiError::make(
                'server.error',
                app()->hasDebugModeEnabled()
                    ? $e->getMessage()
                    : 'An unexpected error occurred. Quote the request id when contacting support.',
                500,
            )->toResponse($request);
        });
    })->create();
