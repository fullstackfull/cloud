<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use Stringable;

/**
 * The zone this platform's own hosts live under — and the one thing in this
 * standard the code refuses to decide.
 *
 * ===========================================================================
 * WHY THERE IS NO DEFAULT
 * ===========================================================================
 *
 * A real internal zone is a fact about somebody's network, their resolver,
 * their certificate authority and their registrar. It is not a fact about this
 * repository, and a default would become one the moment it shipped: the first
 * deployment inherits it, the second copies the first, and six months later a
 * string nobody chose is in every certificate and every monitoring label.
 *
 * So `config('infrastructure.naming.internal_dns_suffix')` starts unset and
 * composition refuses until an operator sets it. "Not configured" is a
 * reportable state with a next action, which is a better answer than a name
 * that resolves nowhere.
 *
 * ===========================================================================
 * AND WHY A REFERENCE SUFFIX CAN NEVER BE THE PRODUCTION ONE
 * ===========================================================================
 *
 * `.example`, `.test`, `.invalid`, `.localhost` and the reserved example
 * domains exist so that documents can name a host without naming somebody's
 * host. The reference topology uses them for exactly that. A production
 * deployment that inherited one would compose names that are guaranteed not to
 * resolve, and would do it silently — every host reachable, every name wrong.
 *
 * {@see ReferenceValues} already knows which names those are, label-wise and
 * without substring guesswork, and this asks it rather than keeping a second
 * list that would drift from the first.
 *
 * ===========================================================================
 * COMPOSITION IS NOT REQUIRED
 * ===========================================================================
 *
 * Some hosts have a name before this platform meets them — a provider hands
 * one back, or an operator types the fully qualified name because that is what
 * their inventory says. Those are stored as they are. {@see self::compose()} is
 * for the other case, where the platform holds a host label and a zone and has
 * to put them together; having one place do it means one set of rules about the
 * dot in the middle rather than a concatenation in every caller.
 *
 * @immutable
 */
final readonly class DnsSuffix implements Stringable
{
    /**
     * Where the operator sets the internal zone. Unset by default, on purpose.
     *
     * One key, and there used to be two. `public_dns_suffix` was declared here
     * and in the config, documented in the standard, and read by nothing: no
     * approved product composes a customer-facing hostname out of a platform
     * suffix. Gap 8 removed it rather than describing it as prepared, because
     * a setting that changes nothing is worse than a missing one — an operator
     * who fills it in has been told a lie about what the platform does with
     * it.
     */
    public const string INTERNAL_CONFIG_KEY = 'infrastructure.naming.internal_dns_suffix';

    private function __construct(
        private DnsName $zone,
    ) {}

    /**
     * Why this string cannot be a DNS suffix, or null if it can.
     *
     * `$production` is the whole point of the parameter list: the reference
     * topology needs `reference.example` to be a perfectly good suffix, and
     * production needs it to be impossible. One method, two answers, stated
     * where the caller says which world it is in — the same shape
     * {@see EndpointPolicy} uses.
     */
    public static function problemWith(string $candidate, bool $production): ?string
    {
        $problem = DnsName::problemWith($candidate);

        if ($problem !== null) {
            return sprintf('it is not a DNS zone: %s', $problem);
        }

        $zone = DnsName::canonical($candidate);

        if (! str_contains($zone, '.')) {
            return 'it is a single label — a suffix needs at least a domain and a TLD, such as "dc1.reference.example"';
        }

        if ($production && (new ReferenceValues)->isDocumentationHostname($zone)) {
            return sprintf(
                'it is under a domain reserved for documents and examples (%s), which cannot resolve in production',
                $zone,
            );
        }

        return null;
    }

    public static function tryFrom(string $candidate, bool $production): ?self
    {
        if (self::problemWith($candidate, $production) !== null) {
            return null;
        }

        $zone = DnsName::tryFrom($candidate);

        return $zone === null ? null : new self($zone);
    }

    /**
     * A host label plus this zone, as one name.
     *
     * The label is validated as a label — one component, no dots — because a
     * caller passing `pve-01.old.example` here means to replace a suffix and
     * would otherwise get `pve-01.old.example.new.example`, which is a real
     * name for nothing.
     */
    public function compose(string $hostLabel): ?DnsName
    {
        $label = strtolower(trim($hostLabel));

        if (! DnsName::isLabel($label)) {
            return null;
        }

        return DnsName::tryFrom($label.'.'.$this->zone->value());
    }

    public function value(): string
    {
        return $this->zone->value();
    }

    public function zone(): DnsName
    {
        return $this->zone;
    }

    /** Is that name inside this zone, label-wise? */
    public function covers(DnsName|string $name): bool
    {
        $candidate = $name instanceof DnsName ? $name : DnsName::tryFrom($name);

        return $candidate?->isUnder($this->zone) ?? false;
    }

    public function __toString(): string
    {
        return $this->zone->value();
    }
}
