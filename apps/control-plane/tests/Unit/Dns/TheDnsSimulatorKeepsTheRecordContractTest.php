<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;

/**
 * The record contract, against the controlled fake.
 *
 * The same cases as the adapter, in the same run. The fake used to key its
 * records by `(type, name)` and so could not hold a round-robin pair at all:
 * the oracle reproduced the defect it existed to catch, and an adapter fixed
 * against it would have been punished for the fix.
 */
final class TheDnsSimulatorKeepsTheRecordContractTest extends DnsProviderContractTestCase
{
    private ?FakeDnsProvider $provider = null;

    private ?DnsZone $zone = null;

    protected function provider(): FakeDnsProvider
    {
        return $this->provider ??= new FakeDnsProvider;
    }

    protected function zone(): DnsZone
    {
        return $this->zone ??= $this->provider()->withZone('lynomia.test');
    }

    protected function removeBehindThePlatformsBack(string $id): void
    {
        foreach ($this->provider()->records($this->zone()) as $record) {
            if ($record->id() === $id) {
                $this->provider()->delete($this->zone(), $record);
            }
        }
    }
}
