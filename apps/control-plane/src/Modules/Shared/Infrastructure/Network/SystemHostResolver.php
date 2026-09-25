<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Network;

use Lynomia\Modules\Shared\Domain\Contracts\HostResolver;

/**
 * The deployment's own resolver, asked for both address families.
 *
 * `gethostbynamel` returns A records only. On its own it made a name with only
 * an AAAA record resolve to nothing — and a name that resolved to nothing used
 * to be accepted with no address checked at all, so a name pointed at an IPv6
 * metadata address was a way past every refusal the policy makes about where a
 * name points. The AAAA half is therefore asked separately and the two answers
 * are joined.
 *
 * The two functions do not look in the same places, and the residual is worth
 * stating rather than discovering. `gethostbynamel` goes through the C
 * library, so it sees `/etc/hosts` and every other `nsswitch` source;
 * `dns_get_record` asks DNS and nothing else. An AAAA record that exists only
 * in `/etc/hosts` or another non-DNS source is therefore invisible to this
 * union. Closing that properly needs `getaddrinfo`, which PHP exposes only
 * through ext-sockets; composer.json does not declare that extension, and
 * relying on one a deployment is not required to have — or declaring it — is
 * a decision rather than a side effect. Where that leaves a name with no
 * address this union can see, the policy refuses it, so an invisible answer
 * is a refusal rather than a pass. Where the name also has an address the
 * union can see, that one is judged and the invisible one is not; arranging
 * that takes editing this host's own `/etc/hosts` or name-service
 * configuration.
 *
 * Not memoised here: a cache in this class would outlive the record it was
 * answering for. The dedicated provider factory, which asks on each power
 * request, holds what it built per endpoint instead.
 */
final readonly class SystemHostResolver implements HostResolver
{
    public function addressesFor(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
