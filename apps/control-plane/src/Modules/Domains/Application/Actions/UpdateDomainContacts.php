<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;

/**
 * Who the registry thinks owns this name.
 *
 * ---------------------------------------------------------------------------
 * Why this is not "edit a row"
 * ---------------------------------------------------------------------------
 *
 * Because the registry's copy is the one that counts. A contact changed here
 * and not there leaves the platform showing a customer one registrant while
 * the WHOIS record — the thing a court, a registrar dispute or a transfer
 * argument actually reads — says somebody else.
 *
 * So the registry is written first and the rows follow. A refusal changes
 * nothing. A timeout changes nothing either, and is reported as a timeout: the
 * change may have gone through, and reconciliation reads it back rather than
 * this action guessing.
 *
 * ---------------------------------------------------------------------------
 * What is not offered
 * ---------------------------------------------------------------------------
 *
 * Changing the registrant on most gTLDs starts a sixty-day transfer lock and,
 * on some, a confirmation exchange with both the old and new registrant. This
 * action does not pretend otherwise: it sends the change and records what came
 * back. It does not tell the customer the change is complete when the registry
 * has only accepted the request.
 */
final readonly class UpdateDomainContacts
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    /**
     * @param  array<string, ContactDetails>  $contacts  keyed by role value
     *
     * @throws DomainRefusedException
     * @throws DomainRegistrarException
     * @throws RegistrarNotAvailableException
     */
    public function execute(Domain $domain, array $contacts): Domain
    {
        if ($contacts === []) {
            throw DomainRefusedException::becauseAContactIsMissing(DomainContactRole::Registrant->value);
        }

        if (! $domain->state->isManageable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $provider = $this->registrars->make($domain->provider);

        if (! $provider->supports(RegistrarCapability::Contacts)) {
            throw DomainRefusedException::becauseTheProviderCannot(RegistrarCapability::Contacts->value);
        }

        // The registry first. Rows that ran ahead of it would be rows that lie.
        $provider->setContacts($domain->name, $contacts);

        DB::transaction(function () use ($domain, $contacts): void {
            foreach ($contacts as $role => $details) {
                DomainContact::query()->updateOrCreate(
                    [
                        'domain_id' => $domain->getKey(),
                        'role' => DomainContactRole::from($role),
                    ],
                    [
                        'name' => $details->name,
                        'organisation' => $details->organisation,
                        'email' => $details->email,
                        'phone' => $details->phone,
                        'address_line_one' => $details->addressLineOne,
                        'address_line_two' => $details->addressLineTwo,
                        'city' => $details->city,
                        'region' => $details->region,
                        'postal_code' => $details->postalCode,
                        'country' => $details->country,
                    ],
                );
            }
        });

        return $domain;
    }
}
