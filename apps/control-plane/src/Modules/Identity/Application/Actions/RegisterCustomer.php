<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\RegistrationAttemptedOnExistingAccount;
use Lynomia\Modules\Rbac\Domain\Enums\Role;

/**
 * Registers a new login together with the customer account it owns.
 *
 * A registration always produces both: a user with no customer cannot be
 * billed or own a service, and creating one lazily later would mean every
 * ordering path has to handle the "no account yet" case.
 */
final readonly class RegisterCustomer
{
    public function __construct(
        private BillingCurrencies $currencies,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     locale?: string,
     *     timezone?: string,
     *     phone?: ?string,
     *     account_type?: string,
     *     company_name?: ?string,
     *     country?: ?string,
     *     currency?: ?string
     * }  $attributes
     * @return array{user: User, customer: Customer}|null null when the address
     *                                                    already has an account
     */
    public function execute(array $attributes): ?array
    {
        $email = strtolower(trim($attributes['email']));

        /*
         * An address that already has an account produces no account and no
         * error - the caller cannot tell the two cases apart, which is the
         * whole point. The owner of the address is told instead; they are the
         * one party entitled to know.
         *
         * This check is not the guarantee. Two simultaneous registrations for
         * the same address both pass it, and the unique index on users.email
         * is what actually stops the second - caught below, answered the same
         * way. A select-then-insert used as the guarantee would be exactly the
         * race this codebase refuses everywhere else.
         */
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $existing->notify(new RegistrationAttemptedOnExistingAccount);

            return null;
        }

        $type = CustomerType::from($attributes['account_type'] ?? CustomerType::Individual->value);

        /*
         * The currency is decided here, once, from two explicit inputs: the
         * country the customer chose and the mapping a human wrote in
         * config/billing.php. What it must never be is a default that nobody
         * mentioned — this action used to fall back to the platform's own
         * currency whenever the request carried none, and a customer in Riyadh
         * found out they were billed in Kuwaiti dinars when their first
         * invoice arrived.
         *
         * A submitted currency has already been checked against the enabled
         * list by the FormRequest; it is asserted again because this action is
         * also reachable from a seeder and a console command, and the assertion
         * is cheaper than the invoice that would otherwise be issued in a
         * currency the payment provider cannot take.
         */
        $country = isset($attributes['country']) && is_string($attributes['country']) && $attributes['country'] !== ''
            ? strtoupper($attributes['country'])
            : null;

        $currency = isset($attributes['currency']) && is_string($attributes['currency']) && $attributes['currency'] !== ''
            ? $this->currencies->assertEnabled($attributes['currency'])
            : $this->currencies->recommendedFor($country);

        // One transaction: a user without their customer account, or a customer
        // with no owner, are both unusable states.
        try {
            [$user, $customer] = DB::transaction(function () use ($attributes, $type, $country, $currency): array {
                $user = User::create([
                    'name' => $attributes['name'],
                    'email' => strtolower(trim($attributes['email'])),
                    'password' => $attributes['password'],
                    'locale' => $attributes['locale'] ?? config('app.locale'),
                    'timezone' => $attributes['timezone'] ?? config('app.timezone'),
                    'phone' => $attributes['phone'] ?? null,
                    'password_changed_at' => now(),
                ]);

                $user->assignRole(Role::Customer->value);

                $customer = Customer::create([
                    'type' => $type,
                    'status' => CustomerStatus::Active,
                    'display_name' => $type === CustomerType::Organization
                        ? ($attributes['company_name'] ?? $attributes['name'])
                        : $attributes['name'],
                    'legal_name' => $type === CustomerType::Organization
                        ? ($attributes['company_name'] ?? null)
                        : null,
                    'currency' => $currency,
                    'billing_email' => strtolower(trim($attributes['email'])),
                    'country' => $country,
                ]);

                $customer->members()->create([
                    'user_id' => $user->id,
                    'role' => CustomerRole::Owner,
                    'accepted_at' => now(),
                ]);

                return [$user, $customer];
            });
        } catch (UniqueConstraintViolationException) {
            // The other half of the race. The index is what actually guarantees
            // one account per address; this is where losing that race is turned
            // into the same silence the pre-check produces, so the two paths
            // are indistinguishable from outside.
            User::query()->where('email', $email)->first()
                ?->notify(new RegistrationAttemptedOnExistingAccount);

            return null;
        }

        // Dispatched outside the transaction: the verification mail must not be
        // queued for a registration that then rolls back.
        event(new Registered($user));

        return ['user' => $user, 'customer' => $customer];
    }
}
