<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use RuntimeException;

/**
 * Exactly what the browser tests need, and nothing else.
 *
 * Separate from DevelopmentSeeder because the two answer to different people.
 * A developer wants a plausible sandbox; a browser test wants fixed values it
 * can assert on — `e2e-web-01` and not "whatever the factory picked this
 * time". Merging them would make every E2E assertion depend on a seeder
 * somebody else is free to change for unrelated reasons.
 *
 * Everything here is deterministic. Nothing is random, because a test that
 * fails one run in fifty teaches people to re-run it.
 *
 * It refuses production, like every other seeder that writes fixtures.
 */
class E2ESeeder extends Seeder
{
    use AnnouncesProgress;

    /** The machine the VPS specs open. */
    public const string VPS_HOSTNAME = 'e2e-web-01';

    /** The physical machine the dedicated specs open. */
    public const string DEDICATED_SERIAL = 'E2E-SN-000117';

    /** An invoice that is payable, so the pay button has something to do. */
    public const string OPEN_INVOICE_NUMBER = 'INV-E2E-0001';

    /** One that is settled, so the two states can be told apart on screen. */
    public const string PAID_INVOICE_NUMBER = 'INV-E2E-0002';

    /**
     * A staff login holding billing authority and nothing else.
     *
     * DevelopmentSeeder's operator is a super admin, which can prove that an
     * admin screen renders but can prove nothing about a boundary: every check
     * passes for it. The permission boundary is only observable from a login
     * that holds one half of the platform and not the other.
     */
    public const string BILLING_ADMIN_EMAIL = 'billing@lynomia.local';

    /** 9.000 KWD — three minor digits, as the currency requires. */
    public const int SUBSCRIPTION_AMOUNT_MINOR = 9_000;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'E2ESeeder must never run in production: it creates fixtures with fixed identifiers.'
            );
        }

        $customer = Customer::query()->where('billing_email', 'customer@lynomia.local')->firstOrFail();

        $this->virtualMachine($customer);
        $this->dedicatedServer($customer);
        $this->subscriptions($customer);
        $this->notifications($customer);
        $this->invoices($customer);
        $this->wallet($customer);
        $this->billingAdmin();

        $this->announce(sprintf(
            'E2E fixtures seeded: machine %s, invoices %s and %s, plus a billing-only staff login.',
            self::VPS_HOSTNAME,
            self::OPEN_INVOICE_NUMBER,
            self::PAID_INVOICE_NUMBER,
        ));
    }

    private function virtualMachine(Customer $customer): void
    {
        if (VirtualMachine::query()->where('hostname', self::VPS_HOSTNAME)->exists()) {
            return;
        }

        $node = ComputeNode::query()->orderBy('created_at')->firstOrFail();

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'label' => 'Cloud VPS — '.self::VPS_HOSTNAME,
        ]);

        $machine = VirtualMachine::factory()
            ->onNode($node)
            ->forService($service)
            ->resources(2, 4096, 80)
            ->create([
                'hostname' => self::VPS_HOSTNAME,
                'os_family' => 'debian',
                'os_version' => '12',
            ]);

        // One of each state the backups API reports differently: a finished
        // one the customer could restore from, and one that stopped being
        // trackable and is waiting for a person. The backups screen renders
        // both, and the browser suite asserts on both — a screen that showed
        // only the happy state would be hiding the case that matters.
        Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'virtual_machine_id' => $machine->getKey(),
            'cluster_id' => $machine->cluster_id,
            'node_name' => 'e2e-node',
            'datastore' => 'e2e-datastore',
        ]);

        Backup::factory()->needingReview()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'virtual_machine_id' => $machine->getKey(),
            'cluster_id' => $machine->cluster_id,
            'node_name' => 'e2e-node',
            'datastore' => 'e2e-datastore',
        ]);
    }

    /**
     * A Billing Admin, for the boundary specs.
     *
     * The role is granted by name and its permissions come from the role's own
     * default set, so this fixture cannot drift from the platform's idea of
     * what a Billing Admin may do — which is the thing under test.
     */
    /**
     * Two subscriptions, because one renewing and one ending are the two
     * things the screen has to tell apart.
     *
     * The renewal date and the cancellation date read from the same column
     * pair, and a screen showing only one of them looks correct on a fixture
     * that only has one. This gives the browser suite both to distinguish.
     */
    /**
     * One physical machine, delivered and running.
     *
     * Present so the browser suite can exercise the rebuild confirmation on a
     * dedicated server — a different screen, a different identifier to type
     * and a different warning from the VPS one, and each of those is a place
     * the wrong copy could ship.
     */
    private function dedicatedServer(Customer $customer): void
    {
        if (DedicatedServer::query()->where('serial', self::DEDICATED_SERIAL)->exists()) {
            return;
        }

        $datacenter = Datacenter::query()->orderBy('created_at')->first() ?? Datacenter::factory()->create();

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'dedicated',
            'label' => 'Dedicated — '.self::DEDICATED_SERIAL,
        ]);

        DedicatedServer::factory()
            ->inDatacenter($datacenter)
            ->create([
                'serial' => self::DEDICATED_SERIAL,
                'status' => DedicatedServerStatus::Active,
                'power_state' => PowerState::On,
                'customer_id' => $customer->getKey(),
                'service_id' => $service->getKey(),
            ]);
    }

    private function subscriptions(Customer $customer): void
    {
        if (Subscription::query()->where('customer_id', $customer->getKey())->exists()) {
            return;
        }

        $plan = Plan::query()->orderBy('created_at')->firstOrFail();

        Subscription::factory()
            ->startingOn(CarbonImmutable::now()->startOfMonth(), BillingPeriod::Monthly)
            ->priced(self::SUBSCRIPTION_AMOUNT_MINOR)
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
            ]);

        Subscription::factory()
            ->startingOn(CarbonImmutable::now()->startOfMonth(), BillingPeriod::Monthly)
            ->priced(self::SUBSCRIPTION_AMOUNT_MINOR)
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                // Cancelled at the end of the period the customer has already
                // paid for, which is what "ends on" means on the screen.
                'cancel_at' => CarbonImmutable::now()->endOfMonth(),
            ]);
    }

    /**
     * One unread notification and one read one.
     *
     * Two, because the inbox's whole job is telling them apart: the unread
     * count, the emphasis and the "mark read" control all behave differently,
     * and a fixture with only one state lets half a screen look finished.
     */
    private function notifications(Customer $customer): void
    {
        if (Notification::query()->where('customer_id', $customer->getKey())->exists()) {
            return;
        }

        Notification::factory()->ofType(NotificationType::ServiceReady)->create([
            'customer_id' => $customer->getKey(),
            'data' => ['service' => self::VPS_HOSTNAME],
            'link' => '/vps',
            'idempotency_key' => 'e2e:service-ready',
        ]);

        Notification::factory()->ofType(NotificationType::InvoiceIssued)->read()->create([
            'customer_id' => $customer->getKey(),
            'data' => [
                'number' => self::OPEN_INVOICE_NUMBER,
                'amount' => 'KWD 9.000',
                'due_date' => CarbonImmutable::now()->addDays(7)->toDateString(),
            ],
            'link' => '/invoices',
            'idempotency_key' => 'e2e:invoice-issued',
        ]);
    }

    private function billingAdmin(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::BILLING_ADMIN_EMAIL],
            [
                'name' => 'Billing Administrator',
                'password' => 'password',
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ],
        );

        $user->syncRoles([Role::BillingAdmin->value]);
    }

    private function invoices(Customer $customer): void
    {
        if (Invoice::query()->where('number', self::OPEN_INVOICE_NUMBER)->exists()) {
            return;
        }

        Invoice::factory()->for($customer)->totalling(Money::of('9.000', 'KWD'))->create([
            'number' => self::OPEN_INVOICE_NUMBER,
            'status' => InvoiceStatus::Open,
            'amount_paid_minor' => 0,
            'issued_at' => now()->subDays(2),
            'due_at' => now()->addDays(12),
        ]);

        Invoice::factory()->for($customer)->totalling(Money::of('25.500', 'KWD'))->create([
            'number' => self::PAID_INVOICE_NUMBER,
            'status' => InvoiceStatus::Paid,
            'amount_paid_minor' => 25500,
            'issued_at' => now()->subMonth(),
            'due_at' => now()->subMonth()->addDays(14),
            'paid_at' => now()->subMonth()->addDay(),
        ]);
    }

    private function wallet(Customer $customer): void
    {
        Wallet::query()->updateOrCreate(
            ['customer_id' => $customer->getKey(), 'currency' => 'KWD'],
            // A non-zero balance so the wallet screen renders an amount rather
            // than an empty state, and a value with fils so the formatting is
            // actually exercised.
            ['balance_minor' => 12750],
        );
    }
}
