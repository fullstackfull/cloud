<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class CountryCurrencyChangeRefusedException extends DomainException
{
    private function __construct(
        string $message,
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

    public static function nothingChanges(): self
    {
        return new self(
            'That is the country and currency the account already has.',
            'account.country_currency_change.nothing_changes',
            422,
        );
    }

    public static function alreadyOpen(string $changeId): self
    {
        return (new self(
            'A change is already open on this account. Withdraw it to ask for a different one.',
            'account.country_currency_change.already_open',
            409,
        ))->withContext(['change_id' => $changeId]);
    }

    public static function notInState(string $changeId, string $state, string $act): self
    {
        return (new self(
            sprintf('This request is %s and cannot be %s.', str_replace('_', ' ', $state), $act),
            'account.country_currency_change.wrong_state',
            409,
        ))->withContext(['change_id' => $changeId, 'state' => $state]);
    }

    public static function blocked(string $changeId): self
    {
        return (new self(
            'This change is blocked. The account must clear the blockers listed on the request before it can be approved.',
            'account.country_currency_change.blocked',
            409,
        ))->withContext(['change_id' => $changeId]);
    }
}
