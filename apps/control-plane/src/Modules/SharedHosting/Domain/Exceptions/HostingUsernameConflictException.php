<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The panel username this job asked for is already another customer's on this
 * node.
 *
 * The (hosting_node_id, username) row is the module's idempotency key, and it
 * only means "this job has been here before" when the row belongs to the same
 * customer. A row left by somebody else that happens to carry the same name is
 * not a retry: reusing it would arm another tenant's account row for this
 * job's account, so the live account on the node would belong to one customer
 * while every row the platform holds about it — ownership, billing, the
 * portal listing, an SSO session, a suspend, a terminate — named another.
 *
 * Refused rather than worked around, because there is no safe alternative on
 * this node: a panel account name is unique per machine, so the platform
 * cannot create a second account under it whatever the database says.
 */
final class HostingUsernameConflictException extends DomainException
{
    public static function forUsername(string $nodeId, string $hostname, string $username): self
    {
        $exception = new self(sprintf(
            'The username "%s" already belongs to another customer on hosting node %s.',
            $username,
            $hostname,
        ));

        return $exception->withContext([
            'node_id' => $nodeId,
            'hostname' => $hostname,
            'username' => $username,
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.username_conflict';
    }

    /**
     * A conflict over a name the request chose, not a fault of the platform.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
