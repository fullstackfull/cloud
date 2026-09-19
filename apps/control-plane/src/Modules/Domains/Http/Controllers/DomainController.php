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
use Lynomia\Modules\Domains\Application\Actions\SetDomainAutoRenew;
use Lynomia\Modules\Domains\Application\Actions\SetDomainNameservers;
use Lynomia\Modules\Domains\Application\Actions\SetTransferLock;
use Lynomia\Modules\Domains\Application\Actions\UpdateDomainContacts;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Http\Requests\OrderDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\RedeemDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\RenewDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\SetAutoRenewRequest;
use Lynomia\Modules\Domains\Http\Requests\SetNameserversRequest;
use Lynomia\Modules\Domains\Http\Requests\SetTransferLockRequest;
use Lynomia\Modules\Domains\Http\Requests\TransferDomainRequest;
use Lynomia\Modules\Domains\Http\Requests\UpdateContactsRequest;
use Lynomia\Modules\Domains\Http\Resources\DomainContactResource;
use Lynomia\Modules\Domains\Http\Resources\DomainOperationResource;
use Lynomia\Modules\Domains\Http\Resources\DomainResource;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
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
        private readonly SetDomainAutoRenew $autoRenew,
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

    /**
     * Whether the platform prepares another term for this name.
     *
     * A setting, so a plain PUT with no idempotency key and no typed
     * confirmation: it is reversible in one click, and Wave 0's confirmation
     * policy puts friction in front of the irreversible, not in front of
     * everything. Audited, because a name lost to auto-renew being off is a
     * question about who turned it off and when.
     */
    public function setAutoRenew(SetAutoRenewRequest $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);
        $wanted = (bool) $request->validated('auto_renew');

        $updated = app(RecordActAtomically::class)->execute(
            fn (): Domain => $this->autoRenew->execute($found, $wanted),
            fn (Domain $changed) => new AuditedAct(
                action: AuditAction::DomainAutoRenewChanged,
                subject: $changed,
                customerId: (string) $changed->customer_id,
                context: ['domain' => $changed->name, 'auto_renew' => $changed->auto_renew],
            ),
        );

        return (new DomainResource($updated))->response();
    }

    /**
     * The registrant on record for this name.
     *
     * `service.manage`, not `service.view`: this is a person's name, home
     * address and telephone number, and a member who may look at the account's
     * services is not owed them. See {@see DomainContactResource} for the rest
     * of the reasoning, and for what stays behind.
     *
     * 404 when no contact has been recorded — a name transferred in before its
     * contacts were read back has none — rather than an empty object that a
     * form would fill itself with blanks from.
     */
    public function contacts(Request $request, string $domain): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($domain);

        /** @var DomainContact $registrant */
        $registrant = DomainContact::query()
            ->where('domain_id', $found->getKey())
            ->where('role', DomainContactRole::Registrant->value)
            ->firstOrFail();

        return (new DomainContactResource($registrant))->response();
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

    /**
     * One of the acting customer's names, by id or by the name itself.
     *
     * The portal's addresses are readable — `/domains/example.com`, not a
     * ULID — because a customer sends one to a colleague and reads it back to
     * support. Both forms resolve through the same `where` on the acting
     * customer, so neither is an oracle: another account's name and a name
     * that was never registered answer with the same 404.
     *
     * The name is lower-cased before it is matched. A registry treats a
     * domain case-insensitively and so does the platform, and a customer who
     * typed a capital should not be told their domain does not exist.
     */
    private function scoped(string $idOrName): Domain
    {
        /** @var Domain */
        return Domain::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->where(fn ($query) => $query
                ->where('id', $idOrName)
                ->orWhere('name', mb_strtolower($idOrName)))
            ->firstOrFail();
    }
}
