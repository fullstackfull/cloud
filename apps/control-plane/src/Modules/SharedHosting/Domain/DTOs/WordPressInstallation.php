<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What a panel says exists.
 *
 * Deliberately not a boolean. "WordPress is installed" is the least useful
 * true statement about a site: what a customer needs is the address that
 * actually serves it, and what the platform needs is the version, so that a
 * site three major releases behind is visible to somebody rather than to
 * whoever exploits it.
 */
final readonly class WordPressInstallation
{
    public function __construct(
        public string $domain,
        public string $siteUrl,
        public string $adminUrl,
        public ?string $version = null,

        /**
         * Whether the panel says this installation exists at all.
         *
         * False is a real answer from a read: it is how reconciliation learns
         * that an install the platform believes in is not there.
         */
        public bool $exists = true,
    ) {}
}
