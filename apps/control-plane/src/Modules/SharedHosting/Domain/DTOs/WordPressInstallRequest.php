<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What to install, where, and for whom.
 *
 * `$adminPassword` passes through and is never persisted: a stored copy would
 * be every customer's site credentials in one table. InstallWordPressHandler
 * mints it at the moment of the install and drops it once the installer has
 * it. It used to be minted earlier and carried in the job's payload, where the
 * redactor turned it into `[redacted]` before the installer ever saw it
 * (F-45).
 *
 * Getting it to the customer is not something a request object can do, and
 * this one does not promise it.
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
