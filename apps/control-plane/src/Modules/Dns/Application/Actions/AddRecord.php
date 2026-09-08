<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Application\Jobs\PublishRecord;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * Put a record in a zone.
 *
 * The row is written `pending` and a job publishes it. Nothing here calls the
 * provider: an HTTP request that waits on a third party is a request that
 * times out at the customer's end while succeeding at ours, and the customer
 * then adds the record a second time.
 */
final readonly class AddRecord
{
    public function __construct(
        private AssertRecordFitsTheZone $fits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DnsRefusedException
     * @throws InvalidDnsRecordException
     * @throws InvalidDomainNameException
     */
    public function execute(
        DnsZone $zone,
        DnsRecordType $type,
        string $name,
        string $content,
        int $ttl = DnsRecordValue::AUTOMATIC_TTL,
        ?int $priority = null,
        array $data = [],
    ): DnsRecord {
        return DB::transaction(function () use ($zone, $type, $name, $content, $ttl, $priority, $data): DnsRecord {
            $this->fits->execute($zone, $type, $name, $content, $priority, $data);

            /*
             * Built once here as well as in the job. Constructing the value
             * object is what applies the TTL and priority rules, and a row
             * that could not be built must never reach the database — a
             * pending record nothing can ever publish is a row that sits on a
             * customer's screen for ever.
             */
            $value = DnsRecordValue::of($type, $name, $content, $ttl, $priority, $data);

            $record = new DnsRecord;
            $record->forceFill([
                'dns_zone_id' => $zone->getKey(),
                'type' => $type,
                'name' => DomainName::fromString($name, allowWildcard: true)->value(),
                'content' => $value->content(),
                'ttl' => $value->ttl(),
                'priority' => $value->priority(),
                'data' => $data === [] ? null : $data,
                'state' => DnsState::Pending,
            ])->save();

            DB::afterCommit(static function () use ($record): void {
                PublishRecord::dispatch((string) $record->getKey());
            });

            return $record;
        });
    }
}
