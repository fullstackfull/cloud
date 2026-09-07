<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;

/**
 * A brokered panel session on the wire: a link and a username.
 *
 * The URL is the credential for its lifetime — whoever holds it is inside the
 * customer's control panel — which is why it is the only thing here and why
 * it is never logged, cached or persisted on the way out. It has already been
 * checked by the adapter against the node the platform dialled, so it points
 * at that node and nowhere else; a URL a panel chose freely would be a link
 * the portal invites the customer to click, leading to a login page built to
 * look exactly like their panel.
 *
 * What is not here, and must never be added:
 *
 *  - the panel password. The platform does not hold one, which is the entire
 *    reason sessions are brokered rather than passwords replayed.
 *  - the node's hostname, API endpoint or credentials_reference. The hostname
 *    a customer legitimately needs is inside their own session URL; naming the
 *    machine as a field of its own tells them, and anyone they forward this to,
 *    which host to go and look at and who else is on it.
 *  - the panel's own service identifier (`cpaneld` and friends). It is an
 *    implementation detail of the adapter and tells a client nothing it can
 *    act on.
 *
 * @mixin SsoSession
 */
final class HostingPanelSessionResource extends JsonResource
{
    public function __construct(SsoSession $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SsoSession $session */
        $session = $this->resource;

        $expiresAt = $session->expiresAt;

        return [
            'url' => $session->url,
            'username' => $session->username,

            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_in' => $expiresAt === null
                ? null
                : max(0, $expiresAt->getTimestamp() - now()->getTimestamp()),

            /*
             * Stated rather than implied. A client that assumes it may reuse a
             * panel link builds a "open control panel" button out of one URL
             * and every click after the first lands on a login page, which
             * looks like a platform fault rather than a spent token.
             */
            'single_use' => true,
        ];
    }
}
