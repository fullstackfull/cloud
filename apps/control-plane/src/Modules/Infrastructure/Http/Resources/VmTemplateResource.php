<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;

/**
 * An installable image, as an operator sees it.
 *
 * Both display names are here, not one resolved against the reader's locale.
 * The customer's own view of the same image resolves it, because a customer is
 * choosing an operating system; an operator is editing a catalogue entry, and a
 * form that showed only the Arabic name to an Arabic reader would silently drop
 * the English one on save. Named in prose rather than linked, because naming a
 * class in another module's HTTP layer is a cross-module reach whether it is
 * code or a docblock, and the layering gate is right to count it.
 *
 * `installable` is computed rather than stored. A row with no provider
 * reference is a commercial intention — the image has not been staged on the
 * cluster — and placement refuses it. Saying so here means an operator can see
 * why an image they recorded is not being offered, without reading the
 * placement code.
 *
 * @mixin VmTemplate
 */
final class VmTemplateResource extends JsonResource
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
            'cluster_id' => $template->cluster_id,
            'cluster' => $this->whenLoaded('cluster', fn (): ?string => $template->cluster()->first()?->slug),
            'slug' => $template->slug,
            'name' => $template->name,
            'os_family' => $template->os_family->value,
            'os_version' => $template->os_version,
            'architecture' => $template->architecture->value,
            'provider_reference' => $template->provider_reference,
            'cloud_init' => $template->cloud_init,
            'guest_agent' => $template->guest_agent,
            'requires_licence' => $template->requires_licence,
            'licence_note' => $template->licence_note,
            'is_active' => $template->is_active,
            'installable' => $template->is_active
                && $template->provider_reference !== null
                && $template->provider_reference !== '',
        ];
    }
}
