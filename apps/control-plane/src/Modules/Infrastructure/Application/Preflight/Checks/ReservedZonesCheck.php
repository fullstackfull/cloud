<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight\Checks;

use Lynomia\Modules\Dns\Application\Services\ConfiguredReservedZones;
use Lynomia\Modules\Dns\Domain\Enums\NamesBeside;
use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

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
 * boot on it would be wrong: it is the shipped configuration's state. Saying
 * nothing would be wrong too. So it is reported, in the one place an operator
 * already reads before calling an estate ready — and a production estate is
 * not called ready while it holds too little (below).
 *
 * ===========================================================================
 * WHAT IS ASKED OF EACH PLATFORM HOST
 * ===========================================================================
 *
 * A reserved name covers itself, every parent of it and everything beneath
 * it — never a sibling. Holding the control plane's `api.example.net` leaves
 * `www.example.net` and `mail.example.net` to any account, and round two's
 * pass said "everything beneath it" over exactly that state (F-26). So each
 * host the platform's addresses contributed is asked whether the names beside
 * it are held, as a {@see NamesBeside}: held when a reserved name lies
 * strictly above it or it has two labels; not established when it is listed
 * exactly with nothing above it; claimable otherwise.
 *
 * What is not asked, because it cannot be answered without a public-suffix
 * list: whether a listed name is the registrable domain. The names beside a
 * listed name are not held, and a pass says so rather than implying it.
 *
 * ===========================================================================
 * THE STATES
 * ===========================================================================
 *
 *   - **Fail** — an entry in `DNS_RESERVED_ZONES` is not a name. The guard
 *     reads the whole list before it compares a claim with any of it, so every
 *     claim by every account is refused until the entry is corrected. Blocks
 *     in every mode: it is an outage.
 *   - **Blocked** — a production preflight (read-only-real, on a production
 *     installation: the same test the naming findings use) where nothing is
 *     reserved at all, or where a name beside a platform host is claimable.
 *     A production estate needs `DNS_RESERVED_ZONES` to hold the domain above
 *     its hosts, and is not called ready until it does.
 *   - **Warning** — the same two states in any other run, where a rehearsal
 *     is told and not stopped; and, in any run, an address that contributed
 *     no name, named with its {@see NoDerivedName} reason, or a host that is
 *     listed exactly with nothing above it, which may or may not be complete.
 *   - **Pass** — every platform host held with the names beside it: a count of
 *     the entries and the variables they came from, and exactly what that
 *     covers.
 *
 * ===========================================================================
 * WHAT IT NEVER SAYS
 * ===========================================================================
 *
 * A reserved name, a configured value or an address. It names variables and
 * counts entries, which is enough to find the line to change. A preflight
 * report is printed by `infra:preflight` wherever that is run and returned to
 * the Control Center, and an entry that fails to parse is exactly the value
 * most likely to be something that was pasted into the wrong variable.
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
     * @param  bool  $production  a read-only-real run on a production installation
     * @return list<PreflightFinding>
     */
    public function inspect(bool $production): array
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
                    .'compared with it, so until this is corrected every claim by every account is refused as '
                    .'dns.zone.unavailable — new zones cannot be added right now — and logged at error level.',
                    $malformed,
                    $configured,
                    self::LIST,
                    $malformed === 1 ? 'is' : 'are',
                ),
                sprintf(
                    'Correct %s: a comma-separated list of domain names of two or more labels, in ASCII — an '
                    .'internationalised name in its xn-- form — with no scheme, port or path. The entries that do not '
                    .'read are not quoted here; check each one against those rules.',
                    self::LIST,
                ),
            )];
        }

        $byVariable = $zones->derivedByVariable();
        $derived = $zones->derived();
        $underived = $zones->underived();
        $consulted = array_keys($byVariable);

        $sources = [...($configured > 0 ? [self::LIST] : []), ...array_keys($derived)];
        $held = $configured + count($derived);

        if ($held === 0) {
            return [$this->short(
                $production,
                sprintf(
                    'No name is reserved: %s is empty. %s Any name the platform answers on that the zone rules '
                    .'accept can be claimed by any account, with its parents and everything beneath it.%s',
                    self::LIST,
                    self::why($underived),
                    $production ? ' A production estate is not ready while it reserves nothing.' : '',
                ),
                sprintf(
                    'Set %s to the domain this platform answers on — its registrable domain, so that every name '
                    .'beneath it is covered — and set %s to the URLs the platform really answers on, scheme included.',
                    self::LIST,
                    self::listOf($consulted, 'and'),
                ),
            )];
        }

        $beside = $zones->namesBeside();
        $claimable = array_keys(array_filter($beside, static fn (NamesBeside $b): bool => $b === NamesBeside::Claimable));
        $onlyItself = array_keys(array_filter($beside, static fn (NamesBeside $b): bool => $b === NamesBeside::OnlyItselfListed));

        $sentences = array_filter([
            self::why($underived),
            $claimable === [] ? '' : sprintf(
                'Nothing reserved lies above the host of %s — %s holds no name above %s — so the names beside %s, '
                .'others under the same parent domain such as its www. and mail., can be claimed by any account.',
                self::listOf($claimable, 'or'),
                self::LIST,
                count($claimable) === 1 ? 'it' : 'either',
                count($claimable) === 1 ? 'it' : 'each',
            ),
            $onlyItself === [] ? '' : sprintf(
                'The host of %s is listed in %s exactly, with nothing above it reserved. If it is a registrable domain '
                .'that is complete; if it is not, the names beside it can be claimed by any account. This check cannot '
                .'tell which without a public-suffix list.',
                self::listOf($onlyItself, 'and'),
                self::LIST,
            ),
            sprintf('%d name(s) reserved, from %s.', $held, implode(', ', $sources)),
        ]);

        $summary = implode(' ', $sentences);

        if ($claimable !== []) {
            return [$this->short(
                $production,
                $summary.($production ? ' A production estate is not ready while a name beside its own can be claimed.' : ''),
                sprintf(
                    'List in %s the registrable domain above the host of %s, so that every name beside it is covered.',
                    self::LIST,
                    self::listOf($claimable, 'and'),
                ),
            )];
        }

        if ($underived !== [] || $onlyItself !== []) {
            return [PreflightFinding::warning(
                self::ID,
                CheckCategory::Configuration,
                self::TARGET,
                $summary,
                implode(' ', array_filter([
                    $underived === [] ? '' : sprintf(
                        'If %s answers on a domain name, set it to that URL, scheme included, or list the name — '
                        .'better, the registrable domain above it — in %s. If it is meant to answer on an IP address '
                        .'or a single label, this is expected.',
                        self::listOf(array_keys($underived), 'or'),
                        self::LIST,
                    ),
                    $onlyItself === [] ? '' : sprintf(
                        'If the host of %s is not a registrable domain, list the registrable domain above it in %s.',
                        self::listOf($onlyItself, 'or'),
                        self::LIST,
                    ),
                ])),
            )];
        }

        return [PreflightFinding::pass(
            self::ID,
            CheckCategory::Configuration,
            self::TARGET,
            sprintf(
                '%d name(s) reserved, from %s. Each covers itself, every parent of it and every name beneath it; '
                .'every name beside the host of %s lies beneath one of them, or is another domain. A name beside a '
                .'listed name is not covered, and whether a listed name is a registrable domain is not checked: '
                .'that would take a public-suffix list.',
                $held,
                implode(', ', $sources),
                self::listOf(array_keys($derived), 'and'),
            ),
            EvidenceClass::Configuration,
        )];
    }

    /**
     * Too little is reserved: blocked on a production estate, a warning on
     * anything else.
     */
    private function short(bool $production, string $summary, string $nextAction): PreflightFinding
    {
        return $production
            ? PreflightFinding::blocked(self::ID, CheckCategory::Configuration, self::TARGET, $summary, BlockerReason::Configuration, $nextAction)
            : PreflightFinding::warning(self::ID, CheckCategory::Configuration, self::TARGET, $summary, $nextAction);
    }

    /**
     * One sentence per address that gave no name, each with its reason.
     *
     * @param  array<string, NoDerivedName>  $underived
     */
    private static function why(array $underived): string
    {
        $sentences = [];

        foreach ($underived as $variable => $reason) {
            $sentences[] = sprintf('%s contributed no name: %s.', $variable, $reason->reason());
        }

        return implode(' ', $sentences);
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
