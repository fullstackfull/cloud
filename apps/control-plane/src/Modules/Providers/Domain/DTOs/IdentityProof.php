<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\DTOs;

/**
 * Evidence that the thing at the other end is the product we meant to reach.
 *
 * ===========================================================================
 * WHY THIS TYPE EXISTS
 * ===========================================================================
 *
 * Phase 30B.0 tried to find out whether the real estate was reachable from
 * here, and found something worse than "no". This project's sandbox reaches
 * the internet through an egress gateway that answers a TLS handshake for
 * **any** name it is asked about — including
 * `this-host-does-not-exist-9f3a2b.invalid` — and presents a certificate that
 * verifies, with `Verify return code: 0 (ok)`, issued by a CA the host
 * trusts.
 *
 * So all three of these are worthless as evidence that a provider exists:
 *
 *   - a TCP connection was accepted,
 *   - a TLS handshake completed,
 *   - the certificate verified against the trust store.
 *
 * That is not a sandbox curiosity. A corporate TLS-inspecting proxy, a captive
 * portal, a cloud load balancer with no backend, a default vhost on the wrong
 * machine and a typo that lands on somebody else's HTTPS service all produce
 * the same three "successes". A connection tester that stopped there would
 * report an estate that does not exist, and the readiness engine would offer
 * products to customers on the strength of it.
 *
 * Only the application layer discriminates, and only by recognising something
 * the product itself would say and an impostor would not: Proxmox's
 * `/api2/json/version` envelope, WHM's `metadata.command`, Cloudflare's
 * `success`/`errors`/`messages` triple, a Redfish service root's
 * `RedfishVersion`, a Stripe `acct_` identifier, an IPMI controller's
 * manufacturer id.
 *
 * ===========================================================================
 * THE THREE ANSWERS, AND WHY THE THIRD IS SEPARATE
 * ===========================================================================
 *
 *   of()                 this is the product, and it accepted the credential
 *   credentialRejected() this is the product, and it refused the credential
 *   notThisProduct()     whatever answered, it is not this product
 *
 * The second and third must never be collapsed. `AuthFailed` tells an operator
 * to rotate a key; `IdentityMismatch` tells them the endpoint is wrong.
 * Rotating a key against a captive portal produces a second wrong answer an
 * hour later, and the operator now distrusts the credential centre as well.
 * So a tester may only report AuthFailed by proving, from the response, that
 * the product it expected is what refused it.
 *
 * ===========================================================================
 * EVIDENCE IS NOT THE UPSTREAM BODY
 * ===========================================================================
 *
 * `evidence` is written for a person and is built from a fixed vocabulary plus
 * values this class has validated character by character. It is never a slice
 * of an upstream response: an upstream body may contain a session cookie, a
 * token echoed back in an error, a customer's hostname or an internal address,
 * and this string is persisted on the connection test, shown on a screen and
 * carried into the audit trail. {@see self::token()} is the only way a value
 * from the wire reaches it.
 */
final readonly class IdentityProof
{
    /**
     * How much of a validated token is worth keeping.
     *
     * Long enough for a version, a product name or an account identifier;
     * short enough that nothing resembling a credential survives whole even
     * if the pattern below were ever loosened.
     */
    private const int TOKEN_LIMIT = 40;

    private function __construct(
        public bool $matched,
        public bool $credentialRejected,
        public string $evidence,
    ) {}

    /**
     * The product answered and this is what it said it was.
     *
     * @param  string  $evidence  A sentence a person can read, e.g. "Proxmox VE 8.2.4".
     */
    public static function of(string $evidence): self
    {
        return new self(matched: true, credentialRejected: false, evidence: $evidence);
    }

    /**
     * The product answered, is provably the right product, and said no to the
     * credential.
     *
     * The evidence still has to name what proved it. A tester that cannot say
     * why it believes the refusal came from the right product must return
     * {@see self::notThisProduct()} instead — an unexplained 401 from an
     * unidentified host is not an authentication failure, it is an unknown
     * host.
     */
    public static function credentialRejected(string $evidence): self
    {
        return new self(matched: true, credentialRejected: true, evidence: $evidence);
    }

    public static function notThisProduct(string $reason): self
    {
        return new self(matched: false, credentialRejected: false, evidence: $reason);
    }

    /**
     * A value from an upstream response, made safe to keep.
     *
     * Everything outside a conservative character set is dropped rather than
     * escaped, and the result is cut to a length no credential this platform
     * handles would survive. A value that reduces to nothing comes back as
     * null, and the caller must then describe the product without quoting it.
     *
     * This is the only door between an upstream body and a stored string, and
     * it is deliberately narrow: a Proxmox release, a WHM version, a Redfish
     * version and a Stripe account id all pass it unchanged, and an HTML error
     * page, a Set-Cookie value and a JWT do not.
     */
    public static function token(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '', (string) $value) ?? '';
        $clean = trim(mb_substr($clean, 0, self::TOKEN_LIMIT));

        return $clean === '' ? null : $clean;
    }
}
