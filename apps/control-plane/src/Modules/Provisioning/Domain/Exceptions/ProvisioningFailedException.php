<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A provisioning attempt that failed in a way the handler could classify.
 *
 * Handlers are expected to throw this rather than an SDK exception, because
 * the classification is the only thing the engine can act on: it decides
 * whether the work is retried, and whether the addresses and capacity the
 * attempt reserved are handed back or held.
 */
final class ProvisioningFailedException extends DomainException
{
    /*
     * Deliberately NOT named $code: Exception already declares an untyped
     * $code, and redeclaring it with a type is a fatal error at class load,
     * which surfaces as a killed PHP process rather than a readable message.
     */
    private string $errorCode = 'provisioning.failed';

    private FailureClass $failureClass = FailureClass::Transient;

    public static function transient(string $message, string $code = 'provisioning.transient', ?Throwable $previous = null): self
    {
        return (new self($message, previous: $previous))->classified(FailureClass::Transient, $code);
    }

    public static function permanent(string $message, string $code = 'provisioning.permanent', ?Throwable $previous = null): self
    {
        return (new self($message, previous: $previous))->classified(FailureClass::Permanent, $code);
    }

    /**
     * The platform stopped waiting. Note what this does not say: it does not
     * say the provider failed, and the engine must not treat it as if it had.
     */
    public static function timedOut(string $message, string $code = 'provisioning.timeout', ?Throwable $previous = null): self
    {
        return (new self($message, previous: $previous))->classified(FailureClass::Timeout, $code);
    }

    public static function capacityExhausted(string $message, string $code = 'provisioning.capacity', ?Throwable $previous = null): self
    {
        return (new self($message, previous: $previous))->classified(FailureClass::Capacity, $code);
    }

    public function failureClass(): FailureClass
    {
        return $this->failureClass;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 502;
    }

    private function classified(FailureClass $failureClass, string $code): self
    {
        $this->failureClass = $failureClass;
        $this->errorCode = $code;
        $this->withContext(['failure_class' => $failureClass->value]);

        return $this;
    }
}
