<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Services;

use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;

/**
 * Which record in a zone a wanted record is.
 *
 * One answer, used by every implementation of the provider contract, because
 * the defect this exists for (F-11) was two implementations each answering it
 * the same wrong way: the Cloudflare adapter took `$existing[0]` of whatever
 * it read at a `(type, name)`, and the fake keyed its whole store by
 * `type|name`. A name that answers with two addresses, or routes mail to a
 * primary and a backup exchanger, holds several records there, and both
 * collapsed them into one — so publishing the second address overwrote the
 * first, and deleting one took the set.
 *
 * Two tiers, in this order:
 *
 *  1. **The identifier.** A record carrying the provider's identifier is the
 *     record under that identifier — if the zone still holds it at the same
 *     type and name. That is what lets an edit change a value in place rather
 *     than add a second one beside it.
 *  2. **The value**, by {@see DnsRecord::saysTheSameAs()}. An identifier the
 *     zone no longer knows — the record was removed in the provider's own
 *     console — falls through to this tier rather than being trusted. And a
 *     record with no identifier at all is found here, which is what lets a
 *     publish whose answer was lost find the record it already created
 *     instead of creating a second (the Timeout Rule).
 *
 * Neither tier is `(type, name)`. There is no "the record at this name".
 *
 * What this cannot know, and does not try to: whether the record the value
 * tier finds was created by *this* row or belongs to another row of the
 * platform's whose value has since moved. From inside a provider the two are
 * the same read with the same answer; only the platform's own table separates
 * them, and the platform's caller settles it (`ClaimProviderRecord`, in the
 * Application layer, with the table's own unique index behind it).
 */
final class DnsRecordIdentity
{
    /**
     * @param  list<DnsRecord>  $present  What the zone holds, however narrowed
     */
    public static function findAmong(DnsRecord $wanted, array $present): ?DnsRecord
    {
        $id = $wanted->id();

        if ($id !== null && $id !== '') {
            foreach ($present as $candidate) {
                if ($candidate->id() === $id
                    && $candidate->type() === $wanted->type()
                    && $candidate->name() === $wanted->name()) {
                    return $candidate;
                }
            }
        }

        foreach ($present as $candidate) {
            if ($candidate->saysTheSameAs($wanted)) {
                return $candidate;
            }
        }

        return null;
    }
}
