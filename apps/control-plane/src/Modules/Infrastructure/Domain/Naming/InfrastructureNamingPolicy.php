<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Naming\LogicalName;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;

/**
 * The one authority on what an infrastructure name may be.
 *
 * ===========================================================================
 * ONE POLICY, NOT ONE REGEX
 * ===========================================================================
 *
 * A storage key and a hostname do not have the same constraints, and a single
 * pattern claiming to validate both would have to be the looser of the two.
 * So this dispatches on the kind of name a field holds ({@see NameKind}) and
 * hands the actual syntax to the value object that owns it:
 * {@see LogicalName} for identifiers people and machines share,
 * {@see DnsName} for names resolvers answer for. What lives here is the
 * mapping from a field to its kind, its scope and its ceiling, and the
 * sentences an operator reads when a value is refused.
 *
 * ===========================================================================
 * WHAT IT WILL NOT DO
 * ===========================================================================
 *
 * It will not rewrite a value that already exists. {@see self::problemWith()}
 * reports; {@see self::normalizeForCreation()} is a separate call a creation
 * path makes deliberately, and it is the only one that changes a string. An
 * identifier in this platform is referenced by orders, audit entries,
 * deployment plans and metric series, and a policy that silently canonicalised
 * one on the way past would break every one of those references to tidy up a
 * spelling.
 *
 * It will not decide the production DNS suffix — {@see DnsSuffix}.
 *
 * It will not decide whether an endpoint is safe to send a request to. That is
 * {@see EndpointPolicy}, it refuses
 * documentation addresses, reserved names, loopback, link-local and cloud
 * metadata for production, and this class neither duplicates nor weakens it.
 * Naming says what a name may look like; the endpoint policy says where the
 * platform may talk. Merging them would produce one class that is the weaker
 * half of both.
 */
final readonly class InfrastructureNamingPolicy
{
    public function __construct(
        private ReferenceNamingExamples $examples = new ReferenceNamingExamples,
    ) {}

    /**
     * Why this value is not a valid name for that field, or null if it is.
     *
     * The message is written to be shown to an operator and to be pasted into
     * a ticket: it names the field, says what is wrong, and where a canonical
     * spelling exists it offers that spelling rather than leaving somebody to
     * guess at the scheme.
     */
    public function problemWith(NamingConcept $concept, string $value): ?string
    {
        return match ($concept->kind()) {
            NameKind::LogicalKey => LogicalName::problemWith($value, $concept->maxLength()),
            NameKind::OperatorCode => LogicalName::problemWith($value, $concept->maxLength(), requireLowerCase: false),
            NameKind::NetworkName => $this->hostnameProblem($value, $concept->maxLength()),
            NameKind::ProviderNative => $this->providerNativeProblem($value, $concept->maxLength()),
            NameKind::DisplayName => $this->displayNameProblem($value, $concept->maxLength()),
        };
    }

    /** Would this value be accepted for that field? */
    public function accepts(NamingConcept $concept, string $value): bool
    {
        return $this->problemWith($concept, $value) === null;
    }

    /**
     * What a creation form should offer to store, given what somebody typed.
     *
     * Only for kinds the platform owns the spelling of. A provider's
     * identifier is returned untouched, because `LOCAL-LVM` may be exactly what
     * Proxmox calls that storage and a normalised copy is a value its API does
     * not know. A display name is returned untouched for the same reason in
     * reverse: it is somebody's wording.
     */
    public function normalizeForCreation(NamingConcept $concept, string $value): string
    {
        return match ($concept->kind()) {
            NameKind::LogicalKey => LogicalName::normalize($value),
            // Case is preserved for a code somebody stencilled on a cabinet;
            // everything else about the shape is canonicalised.
            NameKind::OperatorCode => $this->normalizeOperatorCode($value),
            NameKind::NetworkName => DnsName::canonical($value),
            NameKind::ProviderNative, NameKind::DisplayName => trim($value),
        };
    }

    /**
     * The value uniqueness is judged on, within the concept's scope.
     *
     * Case-insensitive for everything the platform names itself, so that
     * `Node-01` and `node-01` cannot become two rows. Case-SENSITIVE for a
     * provider-native id, because some providers distinguish them and the
     * platform is not entitled to decide that they do not.
     */
    public function collisionKey(NamingConcept $concept, string $value): string
    {
        return match ($concept->kind()) {
            NameKind::LogicalKey, NameKind::OperatorCode => LogicalName::collisionKey($value),
            NameKind::NetworkName => DnsName::canonical($value),
            NameKind::ProviderNative => trim($value),
            NameKind::DisplayName => trim($value),
        };
    }

    /**
     * Is changing this value a migration rather than an edit?
     *
     * True for a logical key, because orders, audit history, desired state and
     * monitoring point at it. An Admin rename action must therefore refuse it
     * and offer the display name instead — which is what an operator wanting a
     * friendlier label actually wants.
     */
    public function isImmutable(NamingConcept $concept): bool
    {
        return $concept->kind()->isStableIdentity();
    }

    /**
     * A safe example of this kind of name, for a form, a document or a test —
     * or null when the model that carries the examples cannot be read.
     *
     * Exposed so that nothing else invents one. Two parts of a codebase each
     * inventing an example is how a scheme acquires a second spelling that
     * looks official.
     */
    public function example(NamingConcept $concept): ?string
    {
        return $this->examples->for($concept);
    }

    /**
     * The zone the platform composes its own hostnames under, if configured.
     *
     * Read through config rather than env() so that a cached configuration is
     * still correct — and so that the value has one reader. Absent is a
     * legitimate state: this platform has no real estate yet, and a suffix
     * invented to fill the hole would be a naming authority nobody chose.
     */
    public function internalDnsSuffix(bool $production): ?DnsSuffix
    {
        $configured = config(DnsSuffix::INTERNAL_CONFIG_KEY);

        return is_string($configured) && trim($configured) !== ''
            ? DnsSuffix::tryFrom($configured, $production)
            : null;
    }

    /**
     * Why the configured internal suffix cannot be used, or null if it can —
     * including the case where there is none, which is not an error.
     */
    public function internalDnsSuffixProblem(bool $production): ?string
    {
        $configured = config(DnsSuffix::INTERNAL_CONFIG_KEY);

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return DnsSuffix::problemWith($configured, $production);
    }

    /**
     * A hostname, and only a hostname.
     *
     * Not fully-qualified-or-else: a bare `pve-01` is a legitimate value for a
     * field an operator fills before a suffix exists, and refusing it would
     * force somebody to invent a zone to satisfy a validator. Whether a
     * particular use needs the qualified form is that use's question — a TLS
     * certificate needs one, a log line does not.
     */
    private function hostnameProblem(string $value, int $maxLength): ?string
    {
        $problem = DnsName::problemWith($value);

        if ($problem !== null) {
            return $problem;
        }

        return strlen(DnsName::canonical($value)) > $maxLength
            ? sprintf('it is longer than %d characters', $maxLength)
            : null;
    }

    /**
     * Somebody else's identifier: kept as they spell it, checked only for the
     * things that would corrupt a request carrying it.
     *
     * No case rule, no dash rule, no character allow-list beyond printable
     * ASCII without whitespace. Proxmox storages are called `local-lvm` and
     * `ceph_prod`; a datastore may be `vm-backups`; a registrar account id may
     * be anything at all. Inventing constraints for those would be this
     * platform asserting a provider contract it has not read.
     */
    private function providerNativeProblem(string $value, int $maxLength): ?string
    {
        if (trim($value) !== $value) {
            return 'it has leading or trailing whitespace, which the provider will not have in its own records';
        }

        if ($value === '') {
            return 'it is empty';
        }

        if (strlen($value) > $maxLength) {
            return sprintf('it is longer than %d characters', $maxLength);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return 'it contains a control character';
        }

        if (preg_match('/\s/', $value) === 1) {
            return 'it contains whitespace';
        }

        return null;
    }

    /**
     * A human label. Any script, including Arabic; no control characters, so a
     * name cannot carry a newline into a log line or a CSV export.
     */
    private function displayNameProblem(string $value, int $maxLength): ?string
    {
        if (trim($value) === '') {
            return 'it is empty';
        }

        if (strlen($value) > $maxLength) {
            return sprintf('it is longer than %d bytes', $maxLength);
        }

        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
            ? 'it contains a control character'
            : null;
    }

    private function normalizeOperatorCode(string $value): string
    {
        $trimmed = trim($value);

        // Same shape as a logical name, with the case left alone: a rack is
        // `A1` on the cabinet and should be `A1` on the screen.
        $collapsed = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $trimmed);

        return trim($collapsed, '-');
    }
}
