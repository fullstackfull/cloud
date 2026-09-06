<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The node has no valid panel licence, so nothing may be provisioned onto it.
 *
 * cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed are commercial products.
 * The platform integrates with them and refuses to work without them; it
 * contains nothing that bypasses, patches or circumvents their licensing, and
 * there is no configuration flag that turns this exception off.
 *
 * The one flag config exposes — hosting.licence.block_provisioning_when_
 * unlicensed — can only ever make the platform stricter about *when* it
 * checks. It cannot make an unlicensed node serve accounts, because the panel
 * itself will not, and pretending otherwise would only move the failure to the
 * customer.
 *
 * Reported with the same error code as the preflight refusal on purpose:
 * "hosting.license_required" is one condition with one meaning wherever it is
 * met, and an operator searching for it must find every occurrence.
 */
final class HostingNodeUnlicensedException extends DomainException
{
    public static function forNode(string $nodeId, string $hostname, ?string $licenceState = null): self
    {
        $exception = new self(sprintf(
            'Hosting node %s has no valid panel licence%s, so no account may be created on it.',
            $hostname,
            $licenceState === null ? '' : ' ('.$licenceState.')',
        ));

        return $exception->withContext([
            'node_id' => $nodeId,
            'hostname' => $hostname,
            'licence_state' => $licenceState,
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.license_required';
    }

    /**
     * Not a client error: the customer's request was valid and the platform
     * cannot serve it from this node.
     */
    public function httpStatus(): int
    {
        return 503;
    }
}
