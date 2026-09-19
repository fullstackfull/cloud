<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;

/**
 * An operating system a customer may reinstall this machine with.
 *
 * The reinstall endpoint has always accepted a `template_id`, and there was no
 * way for a customer to find out what one looked like: the portal offered no
 * choice and the only list lived in a private method of the controller. This
 * publishes exactly the set that method would accept for this machine, so the
 * screen cannot offer an image the API would then refuse — and cannot invent
 * one either, which is what a hard-coded list of common distributions in the
 * frontend would be.
 *
 * What is not published, and why:
 *
 *  - **cluster_id** — which hardware the image is staged on. An operational
 *    fact about the estate; the customer's machine either can use the image or
 *    is not offered it.
 *  - **provider_reference** — the hypervisor's own handle for the image.
 *  - **checksum / checksum_algorithm** — the supply-chain record. Verified by
 *    the platform when the image is staged; a customer cannot act on it, and
 *    publishing it invites a client to think it should.
 *  - **slug** — the platform's join key. The name and the version are what a
 *    person picks by.
 *
 * `supports_ssh_keys` is the honest gate on the key field: without unattended
 * setup the platform has no way to install a key, so a form that accepted one
 * would be collecting something it intends to drop.
 *
 * @mixin VmTemplate
 */
final class InstallableTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VmTemplate $template */
        $template = $this->resource;

        return [
            'id' => (string) $template->getKey(),
            'name' => $template->nameFor(app()->getLocale()),
            'os_family' => $template->os_family->value,
            'os_version' => $template->os_version,
            'architecture' => $template->architecture->value,
            'supports_ssh_keys' => $template->supportsUnattendedSetup(),
            'requires_licence' => $template->requires_licence,
        ];
    }
}
