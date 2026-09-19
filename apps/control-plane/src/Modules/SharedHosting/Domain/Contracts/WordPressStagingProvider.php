<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Contracts;

use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressCopyRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallation;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressPushRequest;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A toolkit that can copy a WordPress site and push a copy back, for the
 * toolkits that can.
 *
 * ===========================================================================
 * WHY THIS IS SEPARATE FROM WordPressInstaller
 * ===========================================================================
 *
 * Because installing WordPress and cloning it are different toolkit
 * features, licensed and present separately. WP Toolkit Deluxe clones and
 * stages; WP Toolkit Lite installs and does not. Softaculous clones; the
 * cPanel "WordPress Manager" does not. A panel that implements the
 * installer and not this is a panel where the button honestly does not
 * exist, rather than one where it throws.
 *
 * ===========================================================================
 * WHAT IS NOT HERE
 * ===========================================================================
 *
 * No cPanel or DirectAdmin implementation, for the reason the installer
 * gives: no toolkit endpoint has been called from this platform against a
 * real node, so what a clone answers, how long it takes, what a timeout
 * means and what a push overwrites have not been observed. This build has
 * one implementation, the fake, and every surface says "not with this
 * panel" for the others.
 *
 * ===========================================================================
 * THE THREE OPERATIONS, AND THE ONE THAT IS DANGEROUS
 * ===========================================================================
 *
 * A staging copy and a clone both create; the worst they can do is leave a
 * half-copied site under a new name. A push to production overwrites the
 * live site with the copy — files, database, or both — and is the one act
 * here that destroys something a customer wrote. The platform asks the
 * production domain to be typed back and says what it holds no backup of
 * before calling this. Nothing retries: a push that timed out may be
 * halfway through the database.
 */
interface WordPressStagingProvider
{
    /**
     * Copy a site to another domain on the same account. The same call
     * serves a staging copy (the target is a subdomain the platform names)
     * and a clone (the target is a domain the customer names).
     *
     * @throws HostingProviderException when the toolkit refuses, or does not
     *                                  answer — and `isIndeterminate()` is what
     *                                  separates a refusal from a half-copied site
     */
    public function copyWordPress(HostingNode $node, WordPressCopyRequest $request): WordPressInstallation;

    /**
     * Overwrite the production site with the staging copy.
     *
     * @throws HostingProviderException as above, and a timeout here is the
     *                                  worst case on this interface: production
     *                                  may be half-overwritten
     */
    public function pushWordPressToProduction(HostingNode $node, WordPressPushRequest $request): WordPressInstallation;
}
