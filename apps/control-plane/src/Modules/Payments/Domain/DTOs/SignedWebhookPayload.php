<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

/**
 * A webhook body together with the headers that authenticate it.
 *
 * Produced by the fake provider so that tests and local development exercise
 * the real ingestion path — signature check included — instead of a bypass
 * that only exists in tests and therefore never proves the check works.
 *
 * @immutable
 */
final readonly class SignedWebhookPayload
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $rawPayload,
        public array $headers,
    ) {}
}
