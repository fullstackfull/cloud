<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Domains\Application\Actions\IssueAuthorisationCode;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainRedemption;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainRegistration;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainRenewal;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainTransfer;
use Lynomia\Modules\Domains\Application\Actions\SetDomainNameservers;
use Lynomia\Modules\Domains\Application\Actions\SetTransferLock;
use Lynomia\Modules\Domains\Application\Actions\UpdateDomainContacts;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Http\Requests\OrderDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\RedeemDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\RenewDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\SetNameserversRequest;
use Lynomia\Modules\Domains\Http\Requests\SetTransferLockRequest;
use Lynomia\Modules\Domains\Http\Requests\TransferDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\UpdateContactsRequest;
use Lynomia\Modules\Domains\Http\Resources\DomainOperationResource;
use Lynomia\Modules\Domains\Http\Resources\DomainResource;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * The names an account holds, and the buying of new ones.
 *
 * **Scoped, not checked.** Every domain is reached through a `where` on the
 * acting customer, so an id from another account is a 404 rather than a 403:
 * there is nothing here to enumerate into a confirmation that a particular
 * account holds a particular name.
 *
 * **Buying takes `service.manage`.** Reading the list takes `service.view`.
 * A registration commits the account to an invoice, and a member who may look
 * at the machines should not be able to spend against the account.
 */
final class DomainController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly OrderDomainRegistration $orders,
        private readonly OrderDomainRenewal $renewals,
        private readonly OrderDomainRedemption $redemptions,
        private readonly OrderDomainTransfer $transfers,
        private readonly SetDomainNameservers $nameservers,
        private readonly UpdateDomainContacts $contacts,
        private readonly SetTransferLock $lock,
        private readonly IssueAuthorisationCode $authorisationCodes,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $domains = Domain::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => DomainResource::collection($domains),
            'meta' => ['total' => $domains->count()],
        ]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new DomainResource($this->scoped($domain)))->response();
    }

    public function store(OrderDomainRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        /** @var array<string, string|null> $registrant */
        $registrant = $request->validated('registrant');

        $operation = app(RecordActAtomically::class)->execute(
            fn (): DomainOperation => $this->orders->execute(
                $this->actingCustomer->get(),
                (string) $request->validated('quote_id'),
                [DomainContactRole::Registrant->value => $this->contact($registrant)],
            ),
            fn (DomainOperation $placed) => new AuditedAct(
                action: AuditAction::DomainRegistrationOrdered,
                subject: $placed,
                customerId: (string) $placed->customer_id,

                /*
                 * The name and the term, and nothing from the contact. An
                 * audit entry is read by operators and kept for years; the
                 * registrant's address does not belong in it, and the domain
                 * row already holds it under encryption.
                 */
                context: [
                    'domain' => $placed->name,
                    'term_years' => $placed->term_years,
                ],
            ),
        );

        return (new DomainOperationResource($operation))->response()->setStatusCode(201);
    }

    public function renew(RenewDomainRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        $operation = app(RecordActAtomically::class)->execute(
            fn (): DomainOperation => $this->renewals->execute(
                $this->actingCustomer->get(),
                $found,
                (string) $request->validated('quote_id'),
            ),
            fn (DomainOperation $placed) => new AuditedAct(
                action: AuditAction::DomainRenewalOrdered,
                subject: $placed,
                customerId: (string) $placed->customer_id,
                context: ['domain' => $placed->name, 'term_years' => $placed->term_years],
            ),
        );

        return (new DomainOperationResource($operation))->response()->setStatusCode(201);
    }

    public function redeem(RedeemDomainRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        $operation = app(RecordActAtomically::class)->execute(
            fn (): DomainOperation => $this->redemptions->execute(
                $this->actingCustomer->get(),
                $found,
                (string) $request->validated('quote_id'),
            ),
            fn (DomainOperation $placed) => new AuditedAct(
                action: AuditAction::DomainRedemptionOrdered,
                subject: $placed,
                customerId: (string) $placed->customer_id,
                context: ['domain' => $placed->name, 'price_minor' => $placed->price_minor, 'currency' => $placed->currency],
            ),
        );

        return (new DomainOperationResource($operation))->response()->setStatusCode(201);
    }

    public function transfer(TransferDomainRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $operation = app(RecordActAtomically::class)->execute(
            fn (): DomainOperation => $this->transfers->execute(
                $this->actingCustomer->get(),
                (string) $request->validated('quote_id'),
                (string) $request->validated('authorisation_code'),
            ),
            fn (DomainOperation $placed) => new AuditedAct(
                action: AuditAction::DomainTransferOrdered,
                subject: $placed,
                customerId: (string) $placed->customer_id,

                // The name, never the code. An audit trail is read by
                // operators for years; a bearer credential does not belong in
                // one even after it has been spent.
                context: ['domain' => $placed->name],
            ),
        );

        return (new DomainOperationResource($operation))->response()->setStatusCode(201);
    }

    public function setNameservers(SetNameserversRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        /** @var list<string> $hosts */
        $hosts = array_values($request->validated('nameservers'));

        $updated = app(RecordActAtomically::class)->execute(
            fn (): Domain => $this->nameservers->execute($found, $hosts),
            fn (Domain $changed) => new AuditedAct(
                action: AuditAction::DomainNameserversChanged,
                subject: $changed,
                customerId: (string) $changed->customer_id,
                context: ['domain' => $changed->name, 'nameservers' => $changed->nameservers],
            ),
        );

        return (new DomainResource($updated))->response();
    }

    public function updateContacts(UpdateContactsRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        /** @var array<string, string|null> $registrant */
        $registrant = $request->validated('registrant');

        $updated = app(RecordActAtomically::class)->execute(
            fn (): Domain => $this->contacts->execute($found, [
                DomainContactRole::Registrant->value => $this->contact($registrant),
            ]),
            fn (Domain $changed) => new AuditedAct(
                action: AuditAction::DomainContactsChanged,
                subject: $changed,
                customerId: (string) $changed->customer_id,

                // That the registrant changed, not to whom. The new details
                // are on the encrypted row; the audit trail records the act.
                context: ['domain' => $changed->name],
            ),
        );

        return (new DomainResource($updated))->response();
    }

    public function setLock(SetTransferLockRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);
        $locked = (bool) $request->validated('locked');

        $updated = app(RecordActAtomically::class)->execute(
            fn (): Domain => $this->lock->execute($found, $locked),
            fn (Domain $changed) => new AuditedAct(
                action: $locked ? AuditAction::DomainLocked : AuditAction::DomainUnlocked,
                subject: $changed,
                customerId: (string) $changed->customer_id,
                context: ['domain' => $changed->name],
            ),
        );

        return (new DomainResource($updated))->response();
    }

    /**
     * The code that lets the customer take this name elsewhere.
     *
     * A POST rather than a GET, because it is an act and not a read: most
     * registrars regenerate the code when asked, and a GET would be repeated
     * by a browser prefetch or a retry. It is audited for the same reason a
     * password reset is — the request itself is the interesting event.
     */
    public function authorisationCode(Request $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        $code = app(RecordActAtomically::class)->execute(
            fn (): string => $this->authorisationCodes->execute($found),
            fn () => new AuditedAct(
                action: AuditAction::DomainAuthorisationCodeIssued,
                subject: $found,
                customerId: (string) $found->customer_id,

                // Never the code itself.
                context: ['domain' => $found->name],
            ),
        );

        return response()->json(['data' => ['authorisation_code' => $code]]);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private function contact(array $fields): ContactDetails
    {
        return new ContactDetails(
            name: (string) $fields['name'],
            email: (string) $fields['email'],
            phone: (string) $fields['phone'],
            addressLineOne: (string) $fields['address_line_one'],
            city: (string) $fields['city'],
            country: strtoupper((string) $fields['country']),
            organisation: $fields['organisation'] ?? null,
            addressLineTwo: $fields['address_line_two'] ?? null,
            region: $fields['region'] ?? null,
            postalCode: $fields['postal_code'] ?? null,
        );
    }

    private function scoped(string $id): Domain
    {
        /** @var Domain */
        return Domain::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->findOrFail($id);
    }
}
