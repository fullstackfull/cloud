<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What was observed on a machine before a panel is installed on it.
 *
 * A value object rather than something the action goes and collects itself,
 * because the collection happens over SSH on a machine that does not belong to
 * the control plane, while the DECISION — refuse or proceed — is policy that
 * has to be identical everywhere and testable without a machine to point at.
 * Splitting them means the refusals can be exercised exhaustively; a checker
 * that shelled out could only ever be tested against whatever host the suite
 * happened to run on.
 *
 * Nulls mean "not observed", which preflight treats as a refusal rather than a
 * pass. A check that could not run has not passed, and a machine commissioned
 * on the strength of a check that silently did not happen is exactly the
 * half-broken node this whole path exists to prevent.
 *
 * @immutable
 */
final readonly class NodePreflightFacts
{
    /**
     * @param  string  $osId  The distribution id as /etc/os-release spells it, e.g. "almalinux".
     * @param  list<string>  $forwardAddresses  A records the hostname resolves to.
     * @param  list<string>  $reverseHostnames  Names the PTR of those addresses gives back.
     * @param  list<string>  $conflictingServices  Web, database, mail or panel software already installed.
     * @param  list<int>  $boundPorts  Ports already listening on the machine.
     * @param  LicenceStatus|null  $licence  The vendor's answer, or null when the check could not run.
     */
    public function __construct(
        public string $hostname,
        public string $osId,
        public string $osVersion,
        public array $forwardAddresses = [],
        public array $reverseHostnames = [],
        public array $conflictingServices = [],
        public array $boundPorts = [],
        public ?LicenceStatus $licence = null,
    ) {}

    /**
     * Whether the hostname is a fully-qualified domain name.
     *
     * The panel signs its own services and stamps outgoing mail with this
     * name, so a bare label cannot be certified and cannot pass an SPF or a
     * reverse-DNS check at any receiving mail server.
     */
    public function hostnameIsFqdn(): bool
    {
        $hostname = rtrim(trim($this->hostname), '.');

        if ($hostname === '' || ! str_contains($hostname, '.') || strlen($hostname) > 253) {
            return false;
        }

        // Labels of letters, digits and inner hyphens, and a final label that
        // is not numeric — "10.0.0.1" contains dots and is not a hostname.
        return preg_match(
            '/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*\.[A-Za-z]{2,63}$/',
            $hostname,
        ) === 1;
    }

    /**
     * Whether any PTR name matches the hostname.
     *
     * Compared case-insensitively and without the trailing dot, because a
     * resolver returns "node1.example.com." and the machine calls itself
     * "node1.example.com" — treating those as different would refuse every
     * correctly configured node in the fleet.
     */
    public function forwardAndReverseAgree(): bool
    {
        if ($this->reverseHostnames === []) {
            return false;
        }

        $expected = strtolower(rtrim(trim($this->hostname), '.'));

        foreach ($this->reverseHostnames as $reverse) {
            if (strtolower(rtrim(trim($reverse), '.')) === $expected) {
                return true;
            }
        }

        return false;
    }
}
