<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Contracts;

/**
 * Where a name points, asked of whoever answers names for this deployment.
 *
 * A seam rather than a call to `gethostbynamel` inside the endpoint policy, for
 * two reasons that are both about the policy being honest.
 *
 * The first is coverage. The policy's refusals about a *name* are only as good
 * as the answer it is given, and a policy that asked the system resolver
 * directly could only be tested against whatever this machine's resolver
 * happened to say — which, for a name pointed at a metadata address, is
 * nothing a test suite can arrange. Behind a contract, a test says what the
 * name resolves to and watches the policy judge it.
 *
 * The second is that the suite must not make real lookups. Tests answer names
 * from a table instead: `Tests\TestCase` binds one for every test that extends
 * it, and a test that builds the policy by hand passes one in. That is a
 * statement about this seam, not about the suite: anything that resolves names
 * without going through here is outside it.
 *
 * An implementation returns every address it can see — IPv4 and IPv6 — and an
 * empty list when it can see none. It does not throw for a name that does not
 * resolve: "no address" is an answer, and the policy refuses it, because a
 * name whose addresses cannot be seen is a name whose destination cannot be
 * checked.
 */
interface HostResolver
{
    /**
     * @return list<string> every address the name resolves to, in no particular order
     */
    public function addressesFor(string $host): array;
}
