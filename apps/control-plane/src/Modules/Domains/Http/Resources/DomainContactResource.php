<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;

/**
 * The registrant this platform gave the registry, read back.
 *
 * ---------------------------------------------------------------------------
 * Why a read exists at all
 * ---------------------------------------------------------------------------
 *
 * The update endpoint has always been write-only, which meant a customer
 * correcting a typo in their own postal address had to retype every field from
 * memory, and a customer who wanted to check what the registry holds could not
 * — while the registry itself may publish the same details in a public WHOIS.
 * Withholding a person's own address from that person protects nobody.
 *
 * ---------------------------------------------------------------------------
 * What that costs, and how it is bounded
 * ---------------------------------------------------------------------------
 *
 * These are the columns the contacts table encrypts: a real name, a home
 * address, a telephone number. So the read is narrower than the account:
 *
 *  - it takes `service.manage`, not `service.view`, because a member added to
 *    watch the account's servers has no business reading the owner's home
 *    address;
 *  - it publishes only the registrant role, which is the only role the update
 *    accepts and therefore the only one a customer can act on;
 *  - it publishes exactly the fields the update takes, so the form can be
 *    filled from it and nothing more travels than has to;
 *  - `provider_reference` — the registrar's own handle for the contact — stays
 *    behind, like every other provider handle.
 *
 * @mixin DomainContact
 */
final class DomainContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DomainContact $contact */
        $contact = $this->resource;

        return [
            'role' => $contact->role->value,
            'name' => $contact->name,
            'organisation' => $contact->organisation,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'address_line_one' => $contact->address_line_one,
            'address_line_two' => $contact->address_line_two,
            'city' => $contact->city,
            'region' => $contact->region,
            'postal_code' => $contact->postal_code,
            'country' => $contact->country,
            'updated_at' => $contact->updated_at->toIso8601String(),
        ];
    }
}
