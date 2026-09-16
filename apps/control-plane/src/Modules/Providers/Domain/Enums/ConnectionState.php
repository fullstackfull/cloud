<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * What happened the last time we tried to reach something.
 *
 * The distinctions matter more than the count suggests. An operator at 2am
 * needs to know whether the credential is wrong, the network is down, the
 * licence has lapsed or the account simply lacks the permission — because
 * those are four different people to wake, and a single "Error" wakes the
 * wrong one.
 */
enum ConnectionState: string
{
    /** Nobody has tried. Not a failure; an absence. */
    case NotTested = 'not_tested';

    /** A test is in flight. */
    case Testing = 'testing';

    /** Reached, authenticated, and able to do what the provider is for. */
    case Connected = 'connected';

    /** Reached and authenticated, but the account can only look. Correct for a DISCOVERY_ONLY target. */
    case ConnectedReadOnly = 'connected_read_only';

    /** The endpoint answered and rejected the credential. */
    case AuthFailed = 'auth_failed';

    /**
     * Something answered over HTTPS and it is not the product this driver speaks to.
     *
     * Deliberately not AuthFailed, and the distinction is the whole reason
     * this case exists. AuthFailed says the right product refused the
     * credential, which sends an operator to rotate a key. This says the
     * credential was never judged, because whatever is at that address is a
     * load balancer error page, a captive portal, a different vendor's panel,
     * or — as Phase 30B.0 found in this project's own sandbox — an egress
     * gateway that answers a trusted TLS handshake for every hostname on
     * earth, including ones that do not exist. Rotating a key fixes none of
     * those. The endpoint is wrong.
     */
    case IdentityMismatch = 'identity_mismatch';

    /**
     * The stored credential is not in the shape this driver's API requires,
     * so nothing was dialled at all.
     *
     * Also deliberately not AuthFailed: reporting that an endpoint rejected a
     * credential it was never sent is inventing an answer, and it sends the
     * operator to the wrong screen — the credential is not wrong at the
     * provider, it is wrong in the credential centre.
     */
    case CredentialMalformed = 'credential_malformed';

    /** Nothing answered: no route, no listener, a firewall. */
    case NetworkFailed = 'network_failed';

    /** Something answered but the TLS handshake did not complete or the certificate did not match. */
    case TlsFailed = 'tls_failed';

    /** Authenticated, and the product says it is not licensed. A purchasing problem, not an engineering one. */
    case LicenceMissing = 'licence_missing';

    /** Authenticated, and the account may not do what we need. Fix the role, not the password. */
    case PermissionInsufficient = 'permission_insufficient';

    /** The provider itself is having an outage. Ours to wait out, not to fix. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The answer was not one we can classify. A person looks. */
    case NeedsReview = 'needs_review';

    /** Did the target answer us at all, whatever it then said? */
    public function reached(): bool
    {
        return match ($this) {
            self::NotTested, self::Testing, self::NetworkFailed, self::TlsFailed, self::CredentialMalformed => false,
            default => true,
        };
    }

    /** Is this a state we can act through, or one that stops work? */
    public function usable(): bool
    {
        return $this === self::Connected || $this === self::ConnectedReadOnly;
    }

    /**
     * The blocker an operator should be shown, or null when nothing is wrong.
     *
     * This is the mapping that stops a screen saying "Error" when the platform
     * knows perfectly well that a licence has expired.
     */
    public function blocker(): ?BlockerReason
    {
        return match ($this) {
            self::NotTested, self::Testing, self::Connected, self::ConnectedReadOnly => null,
            self::AuthFailed, self::CredentialMalformed => BlockerReason::Credentials,
            self::IdentityMismatch => BlockerReason::Configuration,
            self::NetworkFailed, self::TlsFailed => BlockerReason::Network,
            self::LicenceMissing => BlockerReason::Licence,
            self::PermissionInsufficient => BlockerReason::Configuration,
            self::ProviderUnavailable, self::NeedsReview => BlockerReason::Dependency,
        };
    }
}
