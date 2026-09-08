<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Register a name the customer has already paid for.
 *
 * ===========================================================================
 * ONE ATTEMPT
 * ===========================================================================
 *
 * `$tries = 1`, and that is the most important line in this file.
 *
 * A registration is a purchase at a registry. A refusal is an answer — the
 * registry will refuse the same name just as firmly in thirty seconds — and a
 * timeout is not an answer at all: the name may be registered, the term may
 * have started, and the platform may already have been charged. Retrying a
 * timeout is how an account pays twice for one name, or holds a two-year term
 * it asked one year for.
 *
 * So a timeout ends here, in `indeterminate`, and a person or the reconciler
 * settles it against what the registry actually holds. That is the Timeout
 * Rule, and it is the same rule the compute, DNS and hosting modules follow
 * for the same reason.
 *
 * ===========================================================================
 * WHAT IS SENT, AND WHY THE KEY MATTERS
 * ===========================================================================
 *
 * The operation's own idempotency key goes to the registrar. A registrar that
 * honours it will answer a redelivered request with the registration it
 * already made rather than making a second one — which is what makes the
 * failure above recoverable rather than merely visible.
 */
final class RegisterDomainAtRegistrar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** See the class docblock. Neither a refusal nor a timeout is retried. */
    public int $tries = 1;

    public function __construct(
        private readonly string $operationId,
    ) {}

    public function handle(DomainRegistrarFactory $registrars, SecretRedactor $redactor): void
    {
        $operation = DomainOperation::query()->find($this->operationId);

        if ($operation === null || ! $operation->state->mayBeStarted()) {
            /*
             * Already run, already failed, or cancelled before the worker got
             * to it. A redelivered message must not start a second purchase.
             */
            return;
        }

        $domain = $operation->domain;

        if (! $domain instanceof Domain || $domain->state !== DomainState::RegistrationPending) {
            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Running,
            'attempts' => $operation->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $provider = $registrars->make($operation->provider);
        } catch (UnknownRegistrarDriverException $e) {
            /*
             * The name was sold against a driver this build does not contain.
             * Nothing was sent, so this is a plain failure rather than an
             * indeterminate one — but the customer has paid, and the refund is
             * owed.
             */
            $this->fail($operation, $domain, 'domain.registrar_not_available', $redactor->redactString($e->getMessage()));

            return;
        }

        try {
            $registered = $provider->register(new RegistrationRequest(
                name: $domain->name,
                termYears: $domain->term_years,
                contacts: $this->contactsFor($domain),
                nameservers: $domain->nameservers ?? [],
                priceReference: $operation->provider_reference,
                idempotencyKey: $operation->idempotency_key,
            ));
        } catch (RegistrarNotAvailableException $e) {
            /*
             * A registrar with a seat and no integration — `.sy` today. It
             * refuses at the boundary without sending anything, so the failure
             * is plain rather than indeterminate. Caught separately because it
             * is not a DomainRegistrarException: nothing went wrong at a
             * registrar, because there was no registrar to go wrong.
             *
             * Reaching this at all means a name was sold in a namespace the
             * platform cannot serve, which the search and the catalogue both
             * exist to prevent. It is caught anyway: an uncaught exception
             * here would leave the operation in `running` for ever, which is
             * the one outcome worse than a refund.
             */
            $this->fail($operation, $domain, 'domain.registrar_not_available', $redactor->redactString($e->getMessage()));

            return;
        } catch (DomainRegistrarException $e) {
            $message = $redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                /*
                 * The Timeout Rule. The name may or may not be registered, the
                 * money has moved, and no amount of guessing here improves on
                 * saying so. Both rows say the same thing, so neither a screen
                 * nor a sweep can mistake one for progress.
                 */
                $operation->forceFill([
                    'state' => DomainOperationState::Indeterminate,
                    'failure_code' => $e->errorCode(),
                    'failure_message' => $message,
                ])->save();

                $domain->forceFill([
                    'state' => DomainState::Indeterminate,
                    'review_reason' => 'The registrar did not answer the registration. '
                        .'The name may or may not be held; check the registry before acting.',
                ])->save();

                return;
            }

            $this->fail($operation, $domain, $e->errorCode(), $message);

            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Completed,
            'provider_reference' => $registered->providerReference,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => now(),
        ])->save();

        $domain->forceFill([
            'state' => DomainState::Active,
            'provider_reference' => $registered->providerReference,
            'registered_at' => $registered->registeredAt ?? now(),
            'expires_at' => $registered->expiresAt,
            'nameservers' => $registered->nameservers === [] ? $domain->nameservers : $registered->nameservers,
            'transfer_locked' => $registered->transferLocked,
            'review_reason' => null,
        ])->save();
    }

    /**
     * A registration that will not happen, recorded as one.
     *
     * The domain goes to `failed` rather than being deleted: the customer paid
     * for it, the invoice refers to it, and a row that disappears takes the
     * explanation with it. What it must not do is stay in a state that holds
     * the name — somebody else may want it.
     */
    private function fail(DomainOperation $operation, Domain $domain, string $code, string $message): void
    {
        $operation->forceFill([
            'state' => DomainOperationState::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        $domain->forceFill([
            'state' => DomainState::Failed,
            'review_reason' => 'The registrar refused this registration. The customer has paid and is owed a refund.',
        ])->save();
    }

    /**
     * @return array<string, ContactDetails>
     */
    private function contactsFor(Domain $domain): array
    {
        $contacts = [];

        /** @var DomainContact $stored */
        foreach ($domain->contacts as $stored) {
            $contacts[$stored->role->value] = new ContactDetails(
                name: $stored->name,
                email: $stored->email,
                phone: $stored->phone,
                addressLineOne: $stored->address_line_one,
                city: $stored->city,
                country: $stored->country,
                organisation: $stored->organisation,
                addressLineTwo: $stored->address_line_two,
                region: $stored->region,
                postalCode: $stored->postal_code,
            );
        }

        return $contacts;
    }
}
