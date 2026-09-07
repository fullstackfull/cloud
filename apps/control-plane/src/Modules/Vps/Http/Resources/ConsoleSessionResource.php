<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * A console permit on the wire.
 *
 * One secret leaves the platform here and it is the platform's own: an opaque
 * token that is valid against this API, for one machine, once, for a minute.
 *
 * What is not here, and must never be added:
 *
 *  - the hypervisor's console ticket or VNC password. Both are bearer
 *    credentials for a root console on a node that hosts other customers.
 *  - the node's hostname, address or port. A console URL that names
 *    pve-04.lynomia.net tells the customer, and anyone they forward the link
 *    to, exactly which host to go and look at.
 *  - anything derived from the cluster's API credentials.
 *
 * `gateway` is the websocket endpoint the token is redeemed against, read from
 * configuration. It is null when no gateway is configured, which is honest: a
 * fabricated URL would have clients shipping a connect button that fails in
 * the browser rather than a missing field they can check for.
 *
 * @mixin ConsoleSession
 */
final class ConsoleSessionResource extends JsonResource
{
    public function __construct(ConsoleSession $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ConsoleSession $session */
        $session = $this->resource;

        $gateway = config('vps.console.gateway_url');

        return [
            'id' => $session->id,
            'virtual_machine_id' => $session->virtualMachineId,

            'token' => $session->token,
            'gateway' => is_string($gateway) && $gateway !== '' ? $gateway : null,

            'expires_at' => $session->expiresAt->toIso8601String(),
            'expires_in' => $session->expiresInSeconds(),

            /*
             * Stated rather than implied. A client that assumes it may reuse a
             * console permit builds a reconnect loop out of one token, and
             * every reconnect after the first fails in a way that looks like a
             * platform fault.
             */
            'single_use' => true,
            'ttl_seconds' => ConsoleSessionStore::TTL_SECONDS,
        ];
    }
}
