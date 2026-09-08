<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Application\Jobs\PublishRecord;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;

/**
 * Change what a record says.
 *
 * The name and type are fixed. Changing either would be a different record —
 * and a provider's idea of "the record with this id" would then disagree with
 * the platform's, which is how an edit leaves the old value live and adds the
 * new one beside it. A customer who wants a different name deletes and adds,
 * and sees both steps happen.
 *
 * The row goes back to `pending` while the change is published, rather than
 * being written straight over. A screen that showed the new value as live
 * before the provider had taken it would be telling the customer their site
 * had moved when it had not.
 */
final readonly class ChangeRecord
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
    public function execute(DnsRecord $record, string $content, int $ttl, ?int $priority = null, array $data = []): DnsRecord
    {
        return DB::transaction(function () use ($record, $content, $ttl, $priority, $data): DnsRecord {
            /** @var DnsRecord $locked */
            $locked = DnsRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->state->isEditable()) {
                throw DnsRefusedException::notEditable((string) $locked->getKey());
            }

            $zone = $locked->zone()->firstOrFail();

            $this->fits->execute(
                $zone,
                $locked->type,
                $locked->name,
                $content,
                $priority,
                $data,
                ignoring: (string) $locked->getKey(),
            );

            $value = DnsRecordValue::of($locked->type, $locked->name, $content, $ttl, $priority, $data);

            /*
             * A record that never reached the provider goes back to pending
             * from wherever it was; one that is live has to go through pending
             * too, and the enum allows exactly those moves. Writing the new
             * value in the same statement means a row is never observed
             * carrying the old value in the new state.
             */
            $locked->transitionTo(DnsState::Pending, [
                'content' => $value->content(),
                'ttl' => $value->ttl(),
                'priority' => $value->priority(),
                'data' => $data === [] ? null : $data,
                'failure_reason' => null,
            ]);

            DB::afterCommit(static function () use ($locked): void {
                PublishRecord::dispatch((string) $locked->getKey());
            });

            return $locked->refresh();
        });
    }
}
