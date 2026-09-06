<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that scrubs secrets from every record before a handler
 * writes it anywhere.
 *
 * Placing this at the processor level rather than at each call site means a
 * developer cannot accidentally leak a credential by logging a raw exception or
 * an unfiltered request payload: the redaction is not opt-in.
 */
final readonly class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(
        private SecretRedactor $redactor = new SecretRedactor,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($record->extra),
        );
    }
}
