<?php

declare(strict_types=1);

namespace Lynomia\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single JSON error shape every API endpoint returns.
 *
 * Clients — including the platform's own SPA and any customer integration —
 * should never have to parse prose to decide what happened. `code` is stable
 * across releases and safe to branch on; `message` is for humans and may be
 * localised or reworded at any time.
 *
 *     {
 *       "error": {
 *         "code": "order.already_paid",
 *         "message": "This order has already been paid.",
 *         "details": { "order_id": "01J..." },
 *         "request_id": "01J..."
 *       }
 *     }
 */
final readonly class ApiError implements Responsable
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        private string $code,
        private string $message,
        private int $status = 422,
        private array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $code, string $message, int $status = 422, array $details = []): self
    {
        return new self($code, $message, $status, $details);
    }

    public function toResponse($request): JsonResponse
    {
        $payload = [
            'error' => array_filter([
                'code' => $this->code,
                'message' => $this->message,
                'details' => $this->details !== [] ? $this->details : null,
                // Correlates a customer-reported failure with the exact log
                // lines and provisioning job for that request.
                'request_id' => $request instanceof Request
                    ? $request->attributes->get('request_id')
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        return new JsonResponse($payload, $this->status);
    }
}
