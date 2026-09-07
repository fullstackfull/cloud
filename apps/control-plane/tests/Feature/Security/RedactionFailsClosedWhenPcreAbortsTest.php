<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SecretFixtures;
use Tests\TestCase;

/**
 * REGRESSION — the redactor fails closed.
 *
 * SecretRedactor exists so that redaction is a processor and not a call-site
 * convention. redactString() used to ignore a null return from preg_replace,
 * which is exactly what PCRE returns when it gives up — the backtrack limit,
 * the recursion limit, or the JIT stack. The pattern that would have masked
 * the credential then silently did not run.
 *
 * The length of the subject is chosen by whoever produced the string, and for
 * a provider adapter that is a managed device: DirectAdminHostingProvider
 * parses a node's whole response body and hands the result to redact() without
 * truncation. The PEM rule's lazy `.*?` rescans to end-of-string from every
 * `-----BEGIN` position, so about a megabyte of repeated BEGIN markers
 * exhausts pcre.backtrack_limit — and a real key later in the same string used
 * to pass through unmasked.
 */
final class RedactionFailsClosedWhenPcreAbortsTest extends TestCase
{
    #[Test]
    public function a_pattern_that_pcre_abandons_does_not_let_the_secret_through(): void
    {
        $key = SecretFixtures::PRIVATE_KEY_PEM;

        // Enough repetitions of an unterminated BEGIN marker to exhaust the
        // backtrack limit on the PEM rule, with a genuine key on the end.
        $subject = str_repeat(SecretFixtures::PEM_BEGIN_MARKER, 40_000).$key;

        // Guard the premise rather than assuming it: this must really be a
        // PCRE failure, not merely a long string.
        $this->assertNull(
            preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', 'x', $subject),
            'The premise of this test is that PCRE gives up on this subject.',
        );

        $redacted = (new SecretRedactor(['password']))->redactString($subject);

        $this->assertStringNotContainsString(
            'MIIEowIBAAKCAQEAo1s',
            $redacted,
            'A pattern PCRE abandoned must not leave the value it was meant to mask in the output.',
        );
        $this->assertSame(SecretRedactor::PLACEHOLDER, $redacted);
    }

    #[Test]
    public function a_string_pcre_can_handle_is_still_redacted_in_place_and_not_discarded(): void
    {
        $redacted = (new SecretRedactor(['password']))
            ->redactString('GET /api2/json/nodes Authorization: PVEAPIToken=root@pam!cp=deadbeef-cafe');

        $this->assertStringContainsString('/api2/json/nodes', $redacted);
        $this->assertStringNotContainsString('deadbeef-cafe', $redacted);
    }
}
