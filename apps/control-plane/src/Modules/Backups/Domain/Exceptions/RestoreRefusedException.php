<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A restore the platform will not start.
 *
 * Each reason carries its own code and status, because each is a different
 * conversation with the customer: "you typed the hostname wrong" is a form
 * error, "this backup never finished" is a fact about their data, and
 * "a restore is already running" is a race that should reload the screen
 * rather than show a validation message.
 */
final class RestoreRefusedException extends DomainException
{
    private function __construct(
        string $message,
        // Not $code: Exception already owns that name, with a different type
        // and different mutability.
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function confirmationMismatch(): self
    {
        return new self(
            'Type the machine\'s hostname exactly to confirm. A restore replaces every disk on it.',
            'backup.restore_confirmation_mismatch',
            422,
        );
    }

    public static function notRestorable(string $backupId, string $because): self
    {
        return (new self(
            sprintf('This backup cannot be restored: %s.', $because),
            'backup.not_restorable',
            422,
        ))->withContext(['backup_id' => $backupId]);
    }

    public static function alreadyRestoring(string $backupId): self
    {
        return (new self(
            'A restore of this machine is already running.',
            'backup.restore_in_flight',
            409,
        ))->withContext(['backup_id' => $backupId]);
    }
}
