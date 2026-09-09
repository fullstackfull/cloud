<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What to install, where, and for whom.
 *
 * `$adminPassword` passes through and is never persisted. The platform
 * generates it, hands it to the installer, and shows it to the customer once;
 * a stored copy would be every customer's site credentials in one table,
 * protecting nothing that a password reset does not already protect.
 */
final readonly class WordPressInstallRequest
{
    public function __construct(
        public string $username,
        public string $domain,
        public string $adminUsername,
        public string $adminPassword,
        public string $adminEmail,
        public string $siteTitle,

        /** The site's language, which is not the panel's. */
        public string $locale = 'en_US',

        /**
         * Where under the domain WordPress lives. The root, essentially
         * always — a subdirectory install is a thing customers ask for and a
         * thing this platform does not offer, because every screen that says
         * "your site" would then have to ask "which one".
         */
        public string $path = '/',
    ) {}
}
