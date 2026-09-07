<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use RuntimeException;

/**
 * Sample data for local development only: accounts, then the catalogue they can
 * buy from, then the inventory that fulfils it.
 *
 * Refuses to run in production: a seeded super-admin account with a known
 * password is exactly the kind of thing that must never reach a live system.
 * The two seeders it calls refuse independently, so neither can be reached by
 * being invoked directly with `db:seed --class`.
 */
final class DevelopmentSeeder extends Seeder
{
    use AnnouncesProgress;

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

        $this->announce('Development accounts seeded (password: "password").');

        $this->call([
            CatalogueSeeder::class,
            InfrastructureSeeder::class,
        ]);
    }
}
