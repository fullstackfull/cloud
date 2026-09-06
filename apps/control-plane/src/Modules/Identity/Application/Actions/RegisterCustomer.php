<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
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
     * @return array{user: User, customer: Customer}
     */
    public function execute(array $attributes): array
    {
        $type = CustomerType::from($attributes['account_type'] ?? CustomerType::Individual->value);

        // One transaction: a user without their customer account, or a customer
        // with no owner, are both unusable states.
        [$user, $customer] = DB::transaction(function () use ($attributes, $type): array {
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
                'currency' => strtoupper($attributes['currency'] ?? config('billing.default_currency')),
                'billing_email' => strtolower(trim($attributes['email'])),
                'country' => isset($attributes['country']) ? strtoupper($attributes['country']) : null,
            ]);

            $customer->members()->create([
                'user_id' => $user->id,
                'role' => CustomerRole::Owner,
                'accepted_at' => now(),
            ]);

            return [$user, $customer];
        });

        // Dispatched outside the transaction: the verification mail must not be
        // queued for a registration that then rolls back.
        event(new Registered($user));

        return ['user' => $user, 'customer' => $customer];
    }
}
