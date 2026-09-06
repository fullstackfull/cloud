<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The fake panel was constructed in production.
 *
 * The fake reports accounts as created without creating them. In production
 * that means services marked active, welcome mail sent with login details, and
 * invoices raised for hosting that does not exist — and nobody finds out until
 * the customer tries to upload a website.
 */
final class FakeHostingProviderInProductionException extends DomainException
{
    public static function forPanel(string $panel): self
    {
        $exception = new self(sprintf(
            'The "%s" hosting panel adapter creates nothing and must never run in production.',
            $panel,
        ));

        return $exception->withContext(['panel' => $panel]);
    }

    public function errorCode(): string
    {
        return 'hosting.fake_provider_in_production';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
