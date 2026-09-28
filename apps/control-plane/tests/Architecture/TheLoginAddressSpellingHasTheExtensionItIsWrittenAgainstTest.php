<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LoginAddress is written against ext-intl: Normalizer, and ICU's UTS #46
 * through idn_to_ascii() / idn_to_utf8().
 *
 * Without the extension the process does not die —
 * symfony/polyfill-intl-normalizer and symfony/polyfill-intl-idn, installed
 * because symfony/mime, symfony/string and egulias/email-validator require
 * them, define both — but the polyfill's IDNA tables are not ICU's, so a domain could be
 * stored in another spelling than the one LoginAddress describes, and two
 * deployments would disagree about which logins are one. The extension is
 * provisioned by the php_fpm role (php_fpm_extensions in
 * infrastructure/ansible/roles/php_fpm/defaults/main.yml) and named in the
 * setup-php steps of .github/workflows/ci.yml; this goes red wherever the
 * suite runs without it.
 */
final class TheLoginAddressSpellingHasTheExtensionItIsWrittenAgainstTest extends TestCase
{
    #[Test]
    public function ext_intl_is_loaded(): void
    {
        $this->assertTrue(
            extension_loaded('intl'),
            'ext-intl is not loaded: LoginAddress would run on the polyfill, whose IDNA tables are not ICU\'s.',
        );
        $this->assertTrue(class_exists(LoginAddress::class));
    }
}
