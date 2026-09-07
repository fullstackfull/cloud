<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\Contracts;

use Lynomia\Modules\Console\Domain\Exceptions\ConsoleUpstreamUnavailableException;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Turns a redeemed permit into a place to connect.
 *
 * The seam exists so that obtaining a provider's console credential is a
 * server-side step that happens *after* redemption, on the gateway's own
 * connection. Doing it when the permit is issued would put the provider's
 * ticket in the API response, and from there in a browser; doing it in the
 * browser would need the provider's API credentials there.
 *
 * It takes the whole session rather than a machine id because the resolver is
 * the last place that can notice a mismatch between what was authorised and
 * what is about to be dialled.
 */
interface ConsoleUpstreamResolver
{
    /**
     * @throws ConsoleUpstreamUnavailableException
     */
    public function resolve(ConsoleSession $session): ConsoleUpstream;
}
