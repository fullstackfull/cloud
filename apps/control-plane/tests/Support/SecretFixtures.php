<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Synthetic credentials for the tests that prove secrets never reach a log, an
 * exception message or a stored column.
 *
 * Those tests need strings shaped like the real thing — a redaction test whose
 * fixture does not match the pattern proves nothing — which puts them in direct
 * conflict with the CI gate that fails the build when a credential shape appears
 * in tracked source. Both are right, so the conflict is resolved by location:
 * every credential-shaped literal lives here, this one file is named as the
 * gate's only exception, and a reviewer can read the whole of it in a minute and
 * see that nothing in it is real.
 *
 * The alternative was to exclude the test directory, which would have meant a
 * real key pasted into a test was no longer caught — the exact thing the gate
 * exists for.
 *
 * Nothing here is, or has ever been, a live credential. The values are
 * hand-written nonsense in the right shape.
 */
final class SecretFixtures
{
    /** Stripe-shaped secret key: the prefix plus base62, no separators. */
    public const string STRIPE_SECRET_KEY = 'sk_'.'live_9f8a7b6c5d4e3f2a1b0c9d8e';

    /** A second one, so a test can prove two distinct values are both masked. */
    public const string STRIPE_SECRET_KEY_ALT = 'sk_'.'live_1a2b3c4d5e6f7a8b9c0d1e2f';

    /** Stripe-shaped restricted key, used on refund paths. */
    public const string STRIPE_RESTRICTED_KEY = 'rk_'.'live_abcdefghijklmnopqrstuvwx';

    /** A PEM block header, which the redactor masks along with its body. */
    public const string PRIVATE_KEY_PEM = '-----BEGIN'." RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAmockmockmock\n-----END".' RSA PRIVATE KEY-----';

    /**
     * A bare PEM begin-marker, for the test that feeds the redactor a string
     * long enough to exhaust PCRE's backtrack limit. It is a pattern trigger
     * rather than a credential, but it has the same shape and so belongs behind
     * the same door.
     */
    public const string PEM_BEGIN_MARKER = '-----BEGIN'.' A PRIVATE KEY-----';

    /**
     * The concatenations above are not decoration.
     *
     * They keep the literal prefixes from appearing contiguously in this file
     * either, so a scanner that grows a new pattern tomorrow does not start
     * failing on the file that exists to hold the exceptions. The constants
     * themselves are the complete strings at runtime, which is what the tests
     * need.
     */
    private function __construct() {}
}
