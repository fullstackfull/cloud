<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Contracts;

use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallation;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A panel that can install WordPress, for the panels that can.
 *
 * ===========================================================================
 * WHY THIS IS A SEPARATE INTERFACE AND NOT MORE METHODS ON HostingProvider
 * ===========================================================================
 *
 * Because installing WordPress is not something every control panel does. It
 * is done by a toolkit bolted onto the panel — WP Toolkit, Softaculous,
 * Installatron — which is separately licensed, separately configured, and
 * absent from plenty of otherwise healthy nodes.
 *
 * Adding these methods to {@see HostingProvider} would force every adapter to
 * answer for a capability it may not have, and the honest answer for those
 * adapters is a method that throws. This platform has been down that road
 * once: a provider contract full of methods that exist to refuse is a contract
 * that lies about what the platform can do, and the architecture tests here
 * hunt exactly that.
 *
 * So the question "can this node install WordPress" is a type question. A
 * provider that can, implements this; a provider that cannot, does not, and
 * the scheduler will not place a WordPress order on its nodes. Nothing throws
 * and nothing is dead.
 *
 * ===========================================================================
 * WHAT IS NOT HERE, AND WHY
 * ===========================================================================
 *
 * There is no cPanel or DirectAdmin implementation of this interface in this
 * build, and that is a deliberate refusal rather than an oversight.
 *
 * Writing one means writing a client for WP Toolkit's or Softaculous's API
 * without the documentation for the version each node runs, and testing it
 * against a fake shaped like the guess. The tests would pass. The first real
 * installation would fail, and every decision downstream of the guess — what a
 * failure looks like, whether a timeout means an install happened, what the
 * site URL is — would have been made from fiction.
 *
 * The same reasoning that keeps `.sy` out of the domain registrars keeps this
 * out of the panel adapters. When the documentation exists, an adapter
 * implements this interface and nothing else in the platform changes.
 */
interface WordPressInstaller
{
    /**
     * Install WordPress into an account that already exists.
     *
     * The account is created first, by the ordinary hosting path, and this is
     * the second step against it. That ordering is not an implementation
     * detail: it is what lets a WordPress order fail at the install and leave
     * a working hosting account behind rather than nothing at all.
     *
     * The administrator's password travels in the request and is not returned.
     * The platform shows it to the customer once and stores no copy.
     *
     * @throws HostingProviderException when the panel refuses, or does not
     *                                  answer — and `isIndeterminate()` is the
     *                                  only thing that separates a refusal
     *                                  from a half-built site.
     */
    public function installWordPress(
        HostingNode $node,
        WordPressInstallRequest $request,
    ): WordPressInstallation;

    /**
     * What the panel believes about an installation.
     *
     * The read reconciliation is built on, and the read that settles an
     * install this platform stopped waiting for.
     *
     * @throws HostingProviderException
     */
    public function wordPressInstallation(
        HostingNode $node,
        string $username,
        string $domain,
    ): WordPressInstallation;
}
