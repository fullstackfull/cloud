<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight\Checks;

use Lynomia\Modules\Dns\Application\Services\ConfiguredReservedZones;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;

/**
 * Whether the platform's own names are held against being claimed by an
 * account — one finding, `dns.reserved_zones`, for the whole deployment.
 *
 * ===========================================================================
 * WHY A REPORT RATHER THAN A REFUSAL
 * ===========================================================================
 *
 * The guard that refuses a claim is silent whenever it has nothing to refuse,
 * and "nothing is reserved" is the state a deployment starts in. Refusing to
 * boot on it would be wrong: a platform answering on an address or on a single
 * label has nothing an account could claim there either. Saying nothing would
 * be wrong too. So it is reported, in the one place an operator already reads
 * before calling an estate ready.
 *
 * ===========================================================================
 * THE THREE STATES, AND WHY ONLY ONE BLOCKS
 * ===========================================================================
 *
 *   - **Fail** — an entry in `DNS_RESERVED_ZONES` is not a name. The guard
 *     reads the whole list before it compares a claim with any of it, so every
 *     claim by every account is refused until the entry is corrected. That is
 *     the guard working as designed, and it is an outage: the one state here
 *     worth stopping a deployment for.
 *   - **Warning** — nothing is reserved at all, or one of the platform's own
 *     addresses contributed no name. Worth knowing; not a reason to stop.
 *   - **Pass** — a count of the names held and the variables they came from.
 *
 * ===========================================================================
 * WHAT IT NEVER SAYS
 * ===========================================================================
 *
 * A reserved name, a configured value or an address. It names variables and
 * counts entries, which is enough to find the line to change. A preflight
 * report is persisted, rendered and carried into an audit entry, and an entry
 * that fails to parse is exactly the value most likely to be something that
 * was pasted into the wrong variable.
 *
 * The count is of list entries: the listed ones plus the derived ones. Two
 * entries in one tree — a name and a host beneath it — are counted as two,
 * although the first already covers the second.
 *
 * ===========================================================================
 * ONE SOURCE
 * ===========================================================================
 *
 * The list comes from {@see ConfiguredReservedZones}, the same reader the
 * guard in the Dns module's ClaimZone action uses, so what this reports is
 * what the guard enforces.
 */
final readonly class ReservedZonesCheck
{
    private const string ID = 'dns.reserved_zones';

    private const string TARGET = "the platform's own names";

    private const string LIST = 'DNS_RESERVED_ZONES';

    public function __construct(private ConfiguredReservedZones $reserved) {}

    /**
     * @return list<PreflightFinding>
     */
    public function inspect(): array
    {
        $zones = $this->reserved->read();

        $configured = count($zones->configured());
        $malformed = $zones->malformed();

        if ($malformed > 0) {
            return [PreflightFinding::fail(
                self::ID,
                CheckCategory::Configuration,
                self::TARGET,
                sprintf(
                    '%d of the %d entries in %s %s not a domain name. The whole list is read before any claim is '
                    .'compared with it, so until this is corrected every claim by every account is refused, with an '
                    .'error that reads as being about the name the customer typed.',
                    $malformed,
                    $configured,
                    self::LIST,
                    $malformed === 1 ? 'is' : 'are',
                ),
                sprintf(
                    'Correct %s: a comma-separated list of domain names of two or more labels, with no scheme, port '
                    .'or path. The entries that do not read are not quoted here; check each one against those rules.',
                    self::LIST,
                ),
            )];
        }

        $byVariable = $zones->derivedByVariable();
        $derived = $zones->derived();
        $consulted = array_keys($byVariable);
        $empty = array_keys(array_diff_key($byVariable, $derived));

        $sources = [...($configured > 0 ? [self::LIST] : []), ...array_keys($derived)];
        $held = $configured + count($derived);

        if ($held === 0) {
            return [PreflightFinding::warning(
                self::ID,
                CheckCategory::Configuration,
                self::TARGET,
                sprintf(
                    'No name is reserved: %s is empty, and no host in %s is a domain name. Nothing is exposed '
                    .'while the platform answers only on an address or a single label, because no account can claim '
                    .'those either; a domain name it answers on that is in none of these places can be claimed by '
                    .'any account, with its parents and everything beneath it.',
                    self::LIST,
                    self::listOf($consulted, 'or'),
                ),
                sprintf(
                    'Set %s to the domain this platform answers on — its registrable domain, so that every name '
                    .'beneath it is covered — or set %s to the addresses the platform really answers on.',
                    self::LIST,
                    self::listOf($consulted, 'and'),
                ),
            )];
        }

        if ($empty !== []) {
            return [PreflightFinding::warning(
                self::ID,
                CheckCategory::Configuration,
                self::TARGET,
                sprintf(
                    '%s contributed no name: %s host is not a domain name — an address, a single label, or nothing '
                    .'at all — so there is nothing there an account could claim, and nothing is reserved for it. '
                    .'%d name(s) reserved, from %s.',
                    self::listOf($empty, 'and'),
                    count($empty) === 1 ? 'its' : 'their',
                    $held,
                    implode(', ', $sources),
                ),
                sprintf(
                    'Nothing to do if %s is meant to answer on an address or a single label. If it will answer on a '
                    .'domain name, set it to that address, or add the name to %s.',
                    self::listOf($empty, 'and'),
                    self::LIST,
                ),
            )];
        }

        return [PreflightFinding::pass(
            self::ID,
            CheckCategory::Configuration,
            self::TARGET,
            sprintf(
                '%d name(s) reserved, from %s. Each covers itself, every parent of it and everything beneath it.',
                $held,
                implode(', ', $sources),
            ),
            EvidenceClass::Configuration,
        )];
    }

    /**
     * `A`, `A and B`, `A, B and C`.
     *
     * @param  list<string>  $names
     */
    private static function listOf(array $names, string $conjunction): string
    {
        $last = (string) array_pop($names);

        return $names === [] ? $last : sprintf('%s %s %s', implode(', ', $names), $conjunction, $last);
    }
}
