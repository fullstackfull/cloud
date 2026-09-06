<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * The control panel a hosting node runs.
 *
 * Persisted in hosting_nodes.panel, so the values are a schema contract:
 * renaming one orphans every node row that carries it, and with it every
 * account on that node.
 *
 * The enum is deliberately not a feature flag. cPanel/WHM and DirectAdmin are
 * commercial products with their own licences, and this enum records which one
 * a node is licensed for — it never enables, unlocks or substitutes for a
 * licence.
 */
enum HostingPanel: string
{
    case Cpanel = 'cpanel';
    case DirectAdmin = 'directadmin';
    case Fake = 'fake';

    /** Whether this panel reaches real infrastructure. */
    public function isReal(): bool
    {
        return $this !== self::Fake;
    }

    /**
     * Whether the vendor offers an official server-side session endpoint.
     *
     * Both real panels do, which is the only reason the platform brokers SSO
     * at all: the alternative — replaying the customer's panel password into a
     * login form — puts a credential the platform should never hold into a
     * browser, and breaks the moment the vendor changes a form field.
     */
    public function supportsSso(): bool
    {
        return true;
    }

    /**
     * Whether this panel requires a paid licence to run.
     *
     * Used by preflight to decide whether the licence check applies at all.
     * There is no case where the answer is "yes, but continue anyway".
     */
    public function requiresLicence(): bool
    {
        return $this->isReal();
    }

    /**
     * The longest username the panel accepts.
     *
     * cPanel truncates silently at 16 characters on older builds, which is how
     * two customers end up sharing one account name; DirectAdmin allows more.
     * Generating a name longer than this is a create that fails at the panel,
     * or worse, one that succeeds against somebody else's account.
     */
    public function maxUsernameLength(): int
    {
        return match ($this) {
            self::Cpanel => 16,
            self::DirectAdmin => 32,
            self::Fake => 16,
        };
    }
}
