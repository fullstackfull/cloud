<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Contracts;

use Lynomia\Modules\Estate\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Estate\Domain\DTOs\TestTarget;

/**
 * Something that can find out whether we can reach a thing, and what it will
 * let us do.
 *
 * ---------------------------------------------------------------------------
 * The one rule every implementation obeys
 * ---------------------------------------------------------------------------
 *
 * A connection test never changes anything. Not a test VM, not a scratch DNS
 * record, not a zero-amount charge. This is what makes it safe to run against
 * a DISCOVERY_ONLY machine, safe to run on a schedule, and safe to offer as a
 * button an operator can press while wondering whether something is broken.
 *
 * Where a capability genuinely cannot be established without writing — whether
 * this registrar account may really register a name, for instance — the answer
 * is Unknown, and it stays Unknown until Phase 30B exercises it for real. That
 * is not a gap in this interface; it is the line between readiness and
 * verification, and blurring it is what the whole phase is trying not to do.
 *
 * ---------------------------------------------------------------------------
 * Why it takes a target rather than a model
 * ---------------------------------------------------------------------------
 *
 * So that the domain layer never holds an Eloquent model, and so that a tester
 * cannot reach past what it was given — a tester that received a
 * ProviderInstance could load its credential's backend reference, and the
 * whole point of the credential architecture is that nothing outside the
 * secret resolver ever holds one.
 */
interface ConnectionTester
{
    /** The driver this tests: proxmox, cloudflare, cpanel, fake. */
    public function driver(): string;

    /**
     * Try to reach the target and report what was found.
     *
     * Never throws for an ordinary failure — an unreachable host is a result,
     * not an exception, and a test that throws cannot record its own steps.
     * Exceptions are reserved for the caller having asked for something
     * impossible, such as a target this tester does not handle.
     */
    public function test(TestTarget $target): ConnectionResult;
}
