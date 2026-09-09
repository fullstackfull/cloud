<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * One site, and honestly.
 *
 * The three booleans are published beside the state rather than folded into
 * it, because they are what lets a screen tell a customer which of the four
 * steps is outstanding — and therefore whether the answer is "wait", "go and
 * change your DNS", or "talk to us".
 *
 * `is_verified` is the one that matters. It is true only when this platform
 * fetched the site and WordPress answered, which is a different claim from
 * every other field here: those are all somebody else's report.
 *
 * Deliberately absent: the administrator's email address, which is personal
 * data with its own encryption, and the administrator's password, which this
 * platform does not have — it is generated, shown once, and never stored.
 *
 * @mixin WordPressSite
 */
final class WordPressSiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'domain_source' => $this->domain_source->value,
            'domain_id' => $this->domain_id,
            'state' => $this->state->value,

            'is_usable' => $this->state->isUsable(),
            'is_verified' => $this->verified_at !== null,
            'needs_attention' => $this->state->needsAttention(),

            'dns_ready' => $this->dns_ready,
            'installed' => $this->installed,
            'ssl_status' => $this->ssl_status->value,

            'site_url' => $this->site_url,
            'admin_url' => $this->adminUrl(),
            'admin_username' => $this->admin_username,
            'wordpress_version' => $this->wordpress_version,
            'locale' => $this->locale,

            /*
             * The panel's own words about a refusal, already redacted where
             * they were stored. A customer whose install failed needs to see
             * why; most of these are actionable.
             */
            'failure_reason' => $this->failure_reason,

            'hosting_account_id' => $this->hosting_account_id,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
