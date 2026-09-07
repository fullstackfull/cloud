<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A record that cannot be built, and therefore cannot be sent.
 *
 * Every one of these is caught before a provider call rather than after one.
 * A provider that receives an MX with no priority either rejects it — a round
 * trip to learn something already knowable — or accepts it and invents a
 * value, which is worse: the zone then holds a routing preference nobody chose.
 */
final class InvalidDnsRecordException extends DomainException
{
    public static function missingName(DnsRecordType $type): self
    {
        $exception = new self(sprintf('A %s record needs a name.', $type->value));

        return $exception->withContext(['type' => $type->value]);
    }

    public static function missingContent(DnsRecordType $type, string $name): self
    {
        $exception = new self(sprintf('A %s record for %s needs a value.', $type->value, $name));

        return $exception->withContext(['type' => $type->value, 'name' => $name]);
    }

    public static function missingPriority(string $name): self
    {
        $exception = new self(sprintf('An MX record for %s needs a priority.', $name));

        return $exception->withContext(['name' => $name]);
    }

    public static function priorityOutOfRange(string $name, int $priority): self
    {
        $exception = new self(sprintf('The priority for %s must be between 0 and 65535; %d was given.', $name, $priority));

        return $exception->withContext(['name' => $name, 'priority' => $priority]);
    }

    public static function ttlOutOfRange(string $name, int $ttl, int $min, int $max): self
    {
        $exception = new self(sprintf(
            'The TTL for %s must be %d-%d seconds, or 1 to let the zone decide; %d was given.',
            $name,
            $min,
            $max,
            $ttl,
        ));

        return $exception->withContext(['name' => $name, 'ttl' => $ttl]);
    }

    public function errorCode(): string
    {
        return 'dns.invalid_record';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
