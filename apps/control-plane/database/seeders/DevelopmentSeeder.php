<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use RuntimeException;

/**
 * Sample data for local development only.
 *
 * Refuses to run in production: a seeded super-admin account with a known
 * password is exactly the kind of thing that must never reach a live system.
 */
final class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DevelopmentSeeder must never run in production: it creates accounts with known passwords.'
            );
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@lynomia.local'],
            [
                'name' => 'Platform Administrator',
                'password' => 'password',
                'email_verified_at' => Date::now(),
                'password_changed_at' => Date::now(),
            ],
        );
        $admin->syncRoles([Role::SuperAdmin->value]);

        $noc = User::firstOrCreate(
            ['email' => 'noc@lynomia.local'],
            [
                'name' => 'NOC Operator',
                'password' => 'password',
                'email_verified_at' => Date::now(),
                'password_changed_at' => Date::now(),
            ],
        );
        $noc->syncRoles([Role::Noc->value]);

        $customerUser = User::firstOrCreate(
            ['email' => 'customer@lynomia.local'],
            [
                'name' => 'Sample Customer',
                'password' => 'password',
                'email_verified_at' => Date::now(),
                'password_changed_at' => Date::now(),
            ],
        );
        $customerUser->syncRoles([Role::Customer->value]);

        $customer = Customer::firstOrCreate(
            ['billing_email' => 'customer@lynomia.local'],
            [
                'type' => CustomerType::Individual,
                'display_name' => 'Sample Customer',
                'currency' => config('billing.default_currency', 'KWD'),
                'country' => 'KW',
            ],
        );

        $customer->members()->firstOrCreate(
            ['user_id' => $customerUser->id],
            ['role' => CustomerRole::Owner, 'accepted_at' => Date::now()],
        );

        $this->command?->info('Development accounts seeded (password: "password").');
    }
}
