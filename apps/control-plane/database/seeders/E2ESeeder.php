<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Providers\Application\Actions\AssessProvider;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
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

    /**
     * A machine with nothing unresolved against it.
     *
     * `e2e-web-01` deliberately carries a rebuild whose outcome nobody knows,
     * so every disruptive control on it is refused until a person has looked
     * — and since Wave 0 the screen says so and disables them. That leaves
     * nothing to press. This machine is the one the power, reinstall and
     * duplicate-submission specs actually drive.
     */
    public const string OPERABLE_HOSTNAME = 'e2e-app-02';

    /** The physical machine the dedicated specs open. */
    public const string DEDICATED_SERIAL = 'E2E-SN-000117';

    /** An invoice that is payable, so the pay button has something to do. */
    public const string OPEN_INVOICE_NUMBER = 'INV-E2E-0001';

    /** One that is settled, so the two states can be told apart on screen. */
    public const string PAID_INVOICE_NUMBER = 'INV-E2E-0002';

    /**
     * An open invoice worth more than the seeded wallet holds.
     *
     * Both halves of the credit dialogue need driving in a browser, and they
     * are different screens: one says the invoice is covered, the other says
     * what is left to pay by card. A fixture that only ever covered the whole
     * amount would leave the second untested.
     */
    public const string LARGE_INVOICE_NUMBER = 'INV-E2E-0003';

    /*
     * Invoices the Wave 2 money journeys spend.
     *
     * Their own, because those journeys pay invoices and the browser projects
     * share one database: a spec that settles INV-E2E-0003 leaves the Arabic
     * run looking for an open invoice that is now paid. One invoice per
     * journey that moves money, named for what it is for.
     */
    public const string CREDIT_THEN_CARD_INVOICE_NUMBER = 'INV-E2E-W2-0001';

    public const string DECLINE_INVOICE_NUMBER = 'INV-E2E-W2-0002';

    public const string PHONE_PAYMENT_INVOICE_NUMBER = 'INV-E2E-W2-0003';

    public const string ARABIC_DECLINE_INVOICE_NUMBER = 'INV-E2E-W2-0004';

    /**
     * A ticket in mid-conversation, with an internal note on it.
     *
     * The note is the fixture that matters: the customer's screen must not
     * show it and the operator's must, and only a browser can prove both of
     * those about the same row.
     */
    public const string TICKET_REFERENCE = 'LYN-E2E-000001';

    public const string TICKET_SUBJECT = 'Cannot reach my server over SSH';

    public const string TICKET_INTERNAL_NOTE = 'Node 3 firmware reboot at 08:50, do not mention the batch';

    /** The address the machine answers on, asserted by name on two screens. */
    public const string VPS_ADDRESS = '198.51.100.24';

    /** The operable machine's address, in the same seeded subnet. */
    public const string OPERABLE_ADDRESS = '198.51.100.25';

    /** The shared hosting account the hosting screens are proved against. */
    public const string HOSTING_USERNAME = 'e2ehost';

    /** A machine whose service is suspended: every action on it must refuse. */
    /** A colleague on the seeded account, so the team screen has two rows. */
    public const string TEAMMATE_EMAIL = 'teammate@lynomia.local';

    /** An offer nobody has answered, so the invitation list is not empty. */
    public const string PENDING_INVITATION_EMAIL = 'invited@lynomia.local';

    public const string SUSPENDED_HOSTNAME = 'e2e-suspended-01';

    /** One the platform is trying to bring back and cannot confirm. */
    public const string REACTIVATING_HOSTNAME = 'e2e-reactivating-01';

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

    /** A name that works, and one the platform cannot vouch for. */
    private const string HELD_DOMAIN = 'e2e-held.test';

    private const string UNSURE_DOMAIN = 'e2e-unsure.test';

    /** A name that lapsed past its grace window: recoverable, for a penalty. */
    private const string LAPSED_DOMAIN = 'e2e-lapsed.test';

    /** A lapsed name in a namespace whose registry has published no policy. */
    private const string LOST_DOMAIN = 'e2e-lost.example';

    /** A site that works, one waiting on its customer, one waiting on a person. */
    private const string LIVE_SITE = 'e2e-live-site.test';

    private const string WAITING_SITE = 'e2e-waiting-site.test';

    private const string STUCK_SITE = 'e2e-stuck-site.test';

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
        $this->address($customer);
        $this->hostingAccount($customer);
        $this->servicesInTrouble($customer);
        $this->workNobodyCanSettle($customer);
        $this->whatReconciliationFound($customer);
        $this->domains($customer);
        $this->wordpressSites($customer);
        $this->team($customer);
        $this->ticket($customer);
        $this->credentials();
        $this->licences();
        $this->machinesAndProviders();
        $this->secondOperator();
        $this->rack();

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

        $this->operableMachine($customer, $node);
    }

    /**
     * The machine the browser suite is allowed to reboot, stop and rebuild.
     *
     * Its own service, active, on the same node, with no job in any state
     * against it — so the fake compute provider carries every request out
     * synchronously and the screen can be asserted on a real outcome rather
     * than on a refusal.
     */
    private function operableMachine(Customer $customer, ComputeNode $node): void
    {
        if (VirtualMachine::query()->where('hostname', self::OPERABLE_HOSTNAME)->exists()) {
            return;
        }

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'label' => 'Cloud VPS — '.self::OPERABLE_HOSTNAME,
        ]);

        /*
         * An image staged on the cluster, so a rebuild has something to
         * install. Staging is an operator act on a real cluster (the seeded
         * templates deliberately carry no provider reference); on the fake,
         * a reference is a name the fake records and nothing more.
         */
        $template = VmTemplate::query()
            ->where('cluster_id', $node->cluster_id)
            ->where('slug', 'debian-13')
            ->firstOrFail();
        $template->forceFill(['provider_reference' => 'e2e-fake-debian-13'])->save();

        $machine = VirtualMachine::factory()
            ->onNode($node)
            ->forService($service)
            ->resources(1, 2048, 40)
            ->create([
                'hostname' => self::OPERABLE_HOSTNAME,
                'os_family' => 'debian',
                'os_version' => '13',
                'template_id' => $template->getKey(),
                // A rebuild replaces the disk on the storage the platform
                // recorded for it, and refuses rather than guesses when none
                // is recorded. The name is the one the fake was told below.
                'storage_name' => 'local-lvm',
            ]);

        /*
         * And the same machine as the fake hypervisor sees it.
         *
         * A row written straight into the database is a machine the platform
         * believes in and the hypervisor has never heard of, so the first
         * power request is answered "no such machine" and the job goes to
         * review — which is the fake modelling drift correctly, and useless
         * for a fixture whose whole purpose is to be operated. Registering it
         * through the provider's own create call puts it in the shared fleet
         * file the browser suite's API process reads (COMPUTE_FAKE_STATE_PATH);
         * without that variable the call is harmless and forgotten.
         */
        (new FakeComputeProvider)->createVirtualMachine(new CreateVmRequest(
            nodeName: $node->provider_name,
            vmId: (int) $machine->provider_id,
            hostname: self::OPERABLE_HOSTNAME,
            vcpu: 1,
            memoryMib: 2048,
            diskGib: 40,
            storageName: 'local-lvm',
        ));
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

        $server = DedicatedServer::factory()
            ->inDatacenter($datacenter)
            ->create([
                'serial' => self::DEDICATED_SERIAL,
                'status' => DedicatedServerStatus::Active,
                'power_state' => PowerState::On,
                'customer_id' => $customer->getKey(),
                'service_id' => $service->getKey(),
            ]);

        /*
         * Its out-of-band controller, answered by the fake BMC adapter that
         * DEDICATED_PROVIDER=fake selects. Without an endpoint row the power
         * and reinstall endpoints answer 500 ("no controller"), which is a
         * fact about the fixture, not about the platform — and the browser
         * suite has to press those buttons.
         */
        BmcEndpoint::factory()->forServer($server)->create([
            'protocol' => BmcProtocol::Redfish,
            'address' => '192.0.2.117',
            'credentials_reference' => 'e2e-bmc-password',
        ]);
    }

    private function subscriptions(Customer $customer): void
    {
        if (Subscription::query()->where('customer_id', $customer->getKey())->exists()) {
            return;
        }

        $plan = Plan::query()->orderBy('created_at')->firstOrFail();

        /*
         * A second, different plan for the second subscription.
         *
         * The fixture used to put both subscriptions on the same plan at the
         * same price, which made the two rows on the screen identical — and a
         * screen that cannot tell two subscriptions apart cannot be tested for
         * telling two subscriptions apart. The second plan is whatever the
         * catalogue's next tier is; if the catalogue has only one plan, the
         * fixture falls back to it and the hostnames still differ.
         */
        $secondPlan = Plan::query()
            ->where('id', '!=', $plan->getKey())
            ->orderBy('created_at')
            ->first() ?? $plan;

        $renewing = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->startOfMonth(), BillingPeriod::Monthly)
            ->priced(self::SUBSCRIPTION_AMOUNT_MINOR)
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
            ]);

        /*
         * The renewing subscription owns the operable machine, so a plan
         * change on it has a service to resize. The machine was seeded with
         * exactly the smallest tier's shape, which is what this subscription's
         * plan describes; without the link the change-plan screen quoted every
         * option against a subscription that governed nothing, and confirming
         * one moved money for a resize that could never run.
         */
        VirtualMachine::query()
            ->where('hostname', self::OPERABLE_HOSTNAME)
            ->firstOrFail()
            ->service()
            ->update(['subscription_id' => $renewing->getKey(), 'resources' => $plan->resources]);

        $ending = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->startOfMonth(), BillingPeriod::Monthly)
            // A different price as well as a different plan, so the two rows
            // differ in every column a customer would use to tell them apart.
            ->priced(self::SUBSCRIPTION_AMOUNT_MINOR + 3000)
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $secondPlan->getKey(),
                'status' => SubscriptionStatus::Active,
                // Cancelled at the end of the period the customer has already
                // paid for, which is what "ends on" means on the screen.
                'cancel_at' => CarbonImmutable::now()->endOfMonth(),
            ]);

        /*
         * And it owns the other machine, so the screen can say which server
         * each agreement pays for. Cancelling the wrong one of two identical
         * rows is how a customer loses a production machine.
         */
        VirtualMachine::query()
            ->where('hostname', self::VPS_HOSTNAME)
            ->firstOrFail()
            ->service()
            ->update(['subscription_id' => $ending->getKey()]);
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

    /**
     * The machine's address, live and primary.
     *
     * Two screens read it — the VPS list and the addresses page — and neither
     * had a fixture, so both were proved against an empty state that looked
     * exactly like a working one.
     */
    private function address(Customer $customer): void
    {
        if (IpAssignment::query()->where('customer_id', $customer->getKey())->exists()) {
            return;
        }

        $machine = VirtualMachine::query()->where('hostname', self::VPS_HOSTNAME)->firstOrFail();

        $pool = IpPool::query()->firstOrFail();
        $network = Network::query()->first() ?? Network::factory()->create(['bridge' => 'vmbr1']);

        $subnet = Subnet::factory()->forBlock('198.51.100.16/28', gateway: '198.51.100.17')->create([
            'ip_pool_id' => $pool->getKey(),
            'network_id' => $network->getKey(),
        ]);

        $address = IpAddress::factory()->create([
            'subnet_id' => $subnet->getKey(),
            'address' => self::VPS_ADDRESS,
            'status' => IpAddressStatus::Assigned,
        ]);

        IpAssignment::factory()->create([
            'ip_address_id' => $address->getKey(),
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $machine->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);

        /*
         * The operable machine's address too. A rebuild writes the machine's
         * live address back into the new guest and refuses when there is
         * none, so a machine the browser suite rebuilds has to have one.
         */
        $operable = VirtualMachine::query()->where('hostname', self::OPERABLE_HOSTNAME)->firstOrFail();

        $second = IpAddress::factory()->create([
            'subnet_id' => $subnet->getKey(),
            'address' => self::OPERABLE_ADDRESS,
            'status' => IpAddressStatus::Assigned,
        ]);

        IpAssignment::factory()->create([
            'ip_address_id' => $second->getKey(),
            'customer_id' => $customer->getKey(),
            'service_id' => $operable->service_id,
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $operable->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);
    }

    /**
     * One shared hosting account, on a node with a panel the platform can
     * actually talk to.
     */
    private function hostingAccount(Customer $customer): void
    {
        if (HostingAccount::query()->where('username', self::HOSTING_USERNAME)->exists()) {
            return;
        }

        $node = HostingNode::query()->where('panel', HostingPanel::Fake)->first()
            ?? HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $package = HostingPackage::query()->first() ?? HostingPackage::factory()->create();

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'shared_hosting',
            'label' => 'Shared Hosting — '.self::HOSTING_USERNAME,
        ]);

        HostingAccount::factory()->create([
            'hosting_node_id' => $node->getKey(),
            'hosting_package_id' => $package->getKey(),
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'username' => self::HOSTING_USERNAME,
            'primary_domain' => 'e2e-customer.test',
            'status' => HostingAccountStatus::Active,
            'disk_used_mib' => 2_048,
            'bandwidth_used_mib' => 10_240,
            'usage_synced_at' => now(),
        ]);
    }

    /**
     * The two states a customer notices and the happy fixtures cannot show.
     *
     * A suspended service is one the platform has deliberately cut off, and
     * every action on it — power, resize, rebuild, console — must refuse. A
     * reactivating one is worse and rarer: the customer has paid, the provider
     * would not confirm the machine is unlocked, and the platform is honest
     * about it rather than marking the service active because the money
     * arrived. Neither had a browser test, so neither had ever been looked at
     * on a real screen.
     */
    private function servicesInTrouble(Customer $customer): void
    {
        if (VirtualMachine::query()->where('hostname', self::SUSPENDED_HOSTNAME)->exists()) {
            return;
        }

        $node = ComputeNode::query()->orderBy('created_at')->firstOrFail();

        foreach ([
            [self::SUSPENDED_HOSTNAME, ServiceStatus::Suspended, 'Suspended VPS'],
            [self::REACTIVATING_HOSTNAME, ServiceStatus::Reactivating, 'Reactivating VPS'],
        ] as [$hostname, $status, $label]) {
            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => $status,
                'label' => $label.' — '.$hostname,
            ]);

            VirtualMachine::factory()
                ->onNode($node)
                ->forService($service)
                ->resources(1, 2048, 40)
                ->create([
                    'hostname' => $hostname,
                    'os_family' => 'debian',
                    'os_version' => '12',
                ]);
        }
    }

    /**
     * The operator queues, with something in them.
     *
     * An empty screen proves the route renders and nothing else: every
     * decision an operator can take is a control that only appears next to a
     * row. These are the three rows worth having — a rebuild whose outcome
     * nobody knows, a physical rebuild waiting for a person, and a machine the
     * hypervisor disagrees with the platform about.
     */
    private function workNobodyCanSettle(Customer $customer): void
    {
        if (VmReinstall::query()->exists()) {
            return;
        }

        $machine = VirtualMachine::query()->where('hostname', self::VPS_HOSTNAME)->firstOrFail();

        $timedOut = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'last_error' => 'the hypervisor stopped answering while the disk was being replaced',
            'provider' => 'fake',
        ]);

        VmReinstall::query()->create([
            'virtual_machine_id' => $machine->getKey(),
            'service_id' => $machine->service_id,
            'customer_id' => $customer->getKey(),
            'provisioning_job_id' => $timedOut->getKey(),
            'state' => ReinstallState::Indeterminate,
            'state_changed_at' => now()->subMinutes(20),
            // The fact the customer rings about: this disk is gone, or it is
            // not, and the platform does not know which.
            'destroyed_at' => now()->subMinutes(21),
            'failure_code' => 'compute.request_timeout',
            'failure_message' => 'the hypervisor stopped answering while the disk was being replaced',
            'provider_resource_id' => (string) $machine->provider_id,
            'provider_node' => 'e2e-node',
        ]);

        $server = DedicatedServer::query()->where('serial', self::DEDICATED_SERIAL)->firstOrFail();

        $refused = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallDedicated,
            'customer_id' => $customer->getKey(),
            'service_id' => $server->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Permanent,
            'last_error' => 'the installer reported that it could not partition the disks',
            'provider' => 'fake',
        ]);

        DedicatedReinstall::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'service_id' => $server->service_id,
            'customer_id' => $customer->getKey(),
            'provisioning_job_id' => $refused->getKey(),
            'state' => DedicatedReinstallState::NeedsReview,
            'state_changed_at' => now()->subMinutes(35),
            'destructive_started_at' => now()->subMinutes(40),
            'failure_code' => 'dedicated.install_failed',
            'failure_message' => 'the installer reported that it could not partition the disks',
        ]);

        ResourceDrift::query()->create([
            'provider' => 'fake',
            'resource_type' => 'virtual_machine',
            'service_id' => $machine->service_id,
            'provider_reference' => (string) $machine->provider_id,
            'kind' => DriftKind::SuspensionMismatch,
            'severity' => DriftSeverity::Critical,
            'status' => DriftStatus::Open,
            'expected' => ['lock' => 'lynomia-suspended'],
            'observed' => ['lock' => null],
            'occurrences' => 3,
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * The two findings this platform makes about itself, on the screen an
     * operator would read them on.
     *
     * Both are sweeps, and a sweep whose output nobody looks at is a log file.
     * Neither had a browser test, so neither had ever been seen rendered — and
     * a drift row whose `expected`/`observed` pair renders as `{}` is worse
     * than no row at all, because it reads as "checked, and fine".
     *
     * The hosting finding is `MissingAtProvider`: the account is active here
     * and the panel has never heard of it. That is the one a customer notices
     * first, because they are paying for a website that is not being served.
     *
     * The compute finding is a build whose hypervisor task never came back.
     * The job is in review and *not* retried — the machine may exist, may be
     * half-built, or may not exist at all, and the platform will not guess.
     */
    /**
     * Two names: one working, one the platform cannot vouch for.
     *
     * The second is the point. A registration that timed out leaves a row
     * nobody can act on, and the screen has to say so in words that stop the
     * customer ordering it again — which is the one action that turns an
     * uncertainty into a double charge. A fixture for it means the browser
     * suite can check those words are actually on the page, in both languages.
     */
    private function domains(Customer $customer): void
    {
        DomainTld::query()->updateOrCreate(
            ['tld' => 'test'],
            [
                'enabled' => true,
                'provider' => 'fake',
                'allows_registration' => true,
                'allows_transfer' => true,
                'allows_renewal' => true,
                'supports_premium' => true,
                'minimum_term_years' => 1,
                'maximum_term_years' => 10,
                'currency' => 'KWD',
                'registration_price_minor' => 3_500,
                'renewal_price_minor' => 4_000,
                'transfer_price_minor' => 3_500,
                'redemption_price_minor' => 25_000,
                'registration_cost_minor' => 2_800,
                'renewal_cost_minor' => 3_200,
                'transfer_cost_minor' => 2_800,
                'redemption_cost_minor' => 20_000,
                'grace_days' => 30,
                'redemption_days' => 30,
            ],
        );

        Domain::query()->updateOrCreate(
            ['name' => self::HELD_DOMAIN],
            [
                'customer_id' => $customer->getKey(),
                'tld' => 'test',
                'state' => DomainState::Active,
                'provider' => 'fake',
                'provider_reference' => 'fake-e2e-held',
                'term_years' => 1,
                'auto_renew' => true,
                'transfer_locked' => true,
                'nameservers' => ['ns1.lynomia.test', 'ns2.lynomia.test'],
                'registered_at' => now()->subMonths(6),
                'expires_at' => now()->addMonths(6),
            ],
        );

        // A namespace the fake registrar sells whose lifecycle nobody has
        // recorded: no windows, no penalty. Recovery is refused with the
        // reason, never quoted from a guess.
        DomainTld::query()->updateOrCreate(
            ['tld' => 'example'],
            [
                'enabled' => true,
                'provider' => 'fake',
                'allows_registration' => true,
                'allows_transfer' => false,
                'allows_renewal' => true,
                'supports_premium' => false,
                'minimum_term_years' => 1,
                'maximum_term_years' => 5,
                'currency' => 'KWD',
                'registration_price_minor' => 3_000,
                'renewal_price_minor' => 3_000,
                'transfer_price_minor' => 3_000,
                'redemption_price_minor' => null,
                'registration_cost_minor' => 2_000,
                'renewal_cost_minor' => 2_000,
                'transfer_cost_minor' => 2_000,
                'redemption_cost_minor' => null,
                'grace_days' => null,
                'redemption_days' => null,
            ],
        );

        Domain::query()->updateOrCreate(
            ['name' => self::LAPSED_DOMAIN],
            [
                'customer_id' => $customer->getKey(),
                'tld' => 'test',
                'state' => DomainState::Redemption,
                'provider' => 'fake',
                'provider_reference' => 'fake-e2e-lapsed',
                'term_years' => 1,
                'auto_renew' => false,
                'transfer_locked' => true,
                'nameservers' => ['ns1.lynomia.test', 'ns2.lynomia.test'],
                'registered_at' => now()->subYear()->subDays(45),
                'expires_at' => now()->subDays(45),
            ],
        );

        Domain::query()->updateOrCreate(
            ['name' => self::LOST_DOMAIN],
            [
                'customer_id' => $customer->getKey(),
                'tld' => 'example',
                'state' => DomainState::Redemption,
                'provider' => 'fake',
                'provider_reference' => 'fake-e2e-lost',
                'term_years' => 1,
                'auto_renew' => false,
                'transfer_locked' => true,
                'nameservers' => [],
                'registered_at' => now()->subYear()->subDays(45),
                'expires_at' => now()->subDays(45),
            ],
        );

        Domain::query()->updateOrCreate(
            ['name' => self::UNSURE_DOMAIN],
            [
                'customer_id' => $customer->getKey(),
                'tld' => 'test',
                'state' => DomainState::Indeterminate,
                'provider' => 'fake',
                'term_years' => 1,
                'auto_renew' => true,
                'review_reason' => 'The registrar did not answer the registration. '
                    .'The name may or may not be held; check the registry before acting.',
            ],
        );
    }

    /**
     * Three sites: one live, one waiting on the customer, one nobody can fix
     * by retrying.
     *
     * The middle one is the reason this fixture exists. A customer whose name
     * has not finished pointing here is waiting on themselves, and the screen
     * has to say so — a spinner would leave them refreshing a page while
     * nothing happens, because nothing is going to until they act.
     */
    private function wordpressSites(Customer $customer): void
    {
        $account = HostingAccount::query()->where('username', self::HOSTING_USERNAME)->first();

        WordPressSite::query()->updateOrCreate(
            ['domain' => self::LIVE_SITE],
            [
                'customer_id' => $customer->getKey(),
                // On the seeded account, on a fake node: the one site the
                // browser suite can copy and push.
                'hosting_account_id' => $account?->getKey(),
                'domain_source' => WordPressDomainSource::External,
                'state' => WordPressSiteState::Ready,
                'dns_ready' => true,
                'installed' => true,
                'ssl_status' => SslStatus::Active,
                'verified_at' => now()->subHour(),
                'site_url' => 'https://'.self::LIVE_SITE,
                'admin_username' => 'sitemanager',
                'wordpress_version' => '6.7.1',
                'locale' => 'en_US',
            ],
        );

        WordPressSite::query()->updateOrCreate(
            ['domain' => self::WAITING_SITE],
            [
                'customer_id' => $customer->getKey(),
                'domain_source' => WordPressDomainSource::External,
                'state' => WordPressSiteState::AwaitingDns,
                'dns_ready' => false,
                'installed' => true,
                'ssl_status' => SslStatus::Pending,
                'site_url' => 'https://'.self::WAITING_SITE,
                'admin_username' => 'sitemanager',
                'locale' => 'en_US',
            ],
        );

        WordPressSite::query()->updateOrCreate(
            ['domain' => self::STUCK_SITE],
            [
                'customer_id' => $customer->getKey(),
                'domain_source' => WordPressDomainSource::External,
                'state' => WordPressSiteState::Indeterminate,
                'dns_ready' => true,
                'installed' => false,
                'ssl_status' => SslStatus::Unknown,
                'admin_username' => 'sitemanager',
                'locale' => 'en_US',
                'review_reason' => 'The toolkit did not answer the installation.',
            ],
        );
    }

    private function whatReconciliationFound(Customer $customer): void
    {
        $account = HostingAccount::query()->where('username', self::HOSTING_USERNAME)->firstOrFail();

        if (! ResourceDrift::query()->where('resource_type', 'hosting_account')->exists()) {
            /*
             * Two findings about the same account, because the specs need one
             * to read and one to close: resolving a finding takes it out of
             * the default view, and a spec that consumed the only fixture
             * would leave the next one asserting against an empty table.
             */
            ResourceDrift::query()->create([
                'provider' => HostingPanel::Fake->value,
                'resource_type' => 'hosting_account',
                'service_id' => $account->service_id,
                'provider_reference' => $account->username,
                'kind' => DriftKind::MissingAtProvider,
                'severity' => DriftSeverity::Critical,
                'status' => DriftStatus::Open,
                'expected' => ['status' => HostingAccountStatus::Active->value, 'node' => 'e2e-panel'],
                'observed' => ['present' => false],
                'occurrences' => 2,
                'first_seen_at' => now()->subHours(6),
                'last_seen_at' => now()->subMinutes(11),
            ]);

            ResourceDrift::query()->create([
                'provider' => HostingPanel::Fake->value,
                'resource_type' => 'hosting_account',
                'service_id' => $account->service_id,
                'provider_reference' => self::HOSTING_USERNAME.'-old',
                'kind' => DriftKind::OrphanAtProvider,
                'severity' => DriftSeverity::Warning,
                'status' => DriftStatus::Open,
                'expected' => ['status' => HostingAccountStatus::Terminated->value],
                'observed' => ['present' => true, 'node' => 'e2e-panel'],
                'occurrences' => 1,
                'first_seen_at' => now()->subHours(3),
                'last_seen_at' => now()->subMinutes(9),
            ]);
        }

        $machine = VirtualMachine::query()->where('hostname', self::VPS_HOSTNAME)->firstOrFail();

        if (ProvisioningJob::query()->whereNotNull('remote_task_state')->exists()) {
            return;
        }

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::CreateVps,
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'last_error' => 'The hypervisor task this job started has not finished, '
                .'and the platform has stopped waiting for it.',
            'provider' => 'fake',
            'remote_job_id' => 'UPID:e2e-node:0000A1B2:0000C3D4:66000000:qmcreate:900:root@pam:',
            'remote_task_node' => 'e2e-node',
            'remote_task_state' => RemoteTaskStatus::Unknown->value,
            'remote_task_polled_at' => now()->subMinutes(4),
            'remote_task_poll_count' => 9,
        ]);
    }

    /**
     * A second person on the account, and an offer nobody has answered.
     *
     * Both are needed for the team screen to be worth driving: a list with one
     * row cannot show a role being changed or somebody being removed, and an
     * empty invitation table cannot show one being withdrawn.
     *
     * The teammate is `technical` rather than `administrator` because that is
     * the row the specs act on — a role the seeded owner may change and a
     * person the seeded owner may remove.
     */
    private function team(Customer $customer): void
    {
        $teammate = User::firstOrCreate(
            ['email' => self::TEAMMATE_EMAIL],
            [
                'name' => 'Sara Teammate',
                'password' => 'password',
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ],
        );

        $customer->members()->firstOrCreate(
            ['user_id' => $teammate->id],
            ['role' => CustomerRole::Technical, 'accepted_at' => now()],
        );

        CustomerInvitation::query()->firstOrCreate(
            ['customer_id' => $customer->getKey(), 'email' => self::PENDING_INVITATION_EMAIL],
            [
                'role' => CustomerRole::Member,
                // A token nothing will redeem: the specs drive the account's
                // side of an invitation, and a live token in a seeder is a
                // working key checked into the repository.
                'token_hash' => CustomerInvitation::hashOf('e2e-not-a-real-token'),
                'expires_at' => now()->addDays(14),
                'last_sent_at' => now(),
            ],
        );
    }

    /**
     * One live ticket with three messages, one of which is internal.
     */
    private function ticket(Customer $customer): void
    {
        if (SupportTicket::query()->where('reference', self::TICKET_REFERENCE)->exists()) {
            return;
        }

        $opener = User::query()->where('email', 'customer@lynomia.local')->first();
        $agent = User::query()->where('email', 'admin@lynomia.local')->first();

        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->create([
            'customer_id' => $customer->getKey(),
            'opened_by_user_id' => $opener?->getKey(),
            'reference' => self::TICKET_REFERENCE,
            'subject' => self::TICKET_SUBJECT,
            'category' => TicketCategory::Technical,
            'status' => TicketStatus::WaitingForCustomer,
            'priority' => TicketPriority::High,
            'last_reply_at' => now()->subMinutes(20),
            'last_reply_by' => MessageAuthorKind::Operator,
            'first_responded_at' => now()->subMinutes(25),
        ]);

        SupportMessage::query()->create([
            'ticket_id' => $ticket->getKey(),
            'author_user_id' => $opener?->getKey(),
            'author_kind' => MessageAuthorKind::Customer,
            'body' => 'It has been refusing connections since about nine this morning.',
            'is_internal_note' => false,
        ]);

        SupportMessage::query()->create([
            'ticket_id' => $ticket->getKey(),
            'author_user_id' => $agent?->getKey(),
            'author_kind' => MessageAuthorKind::Operator,
            'body' => self::TICKET_INTERNAL_NOTE,
            'is_internal_note' => true,
        ]);

        SupportMessage::query()->create([
            'ticket_id' => $ticket->getKey(),
            'author_user_id' => $agent?->getKey(),
            'author_kind' => MessageAuthorKind::Operator,
            'body' => 'That node was rebooted for firmware. Your machine is back up — can you confirm?',
            'is_internal_note' => false,
        ]);
    }

    private function invoices(Customer $customer): void
    {
        if (Invoice::query()->where('number', self::OPEN_INVOICE_NUMBER)->exists()) {
            return;
        }

        /*
         * Every seeded invoice carries its lines and a billing snapshot.
         *
         * They used to be totals and nothing else, which was enough while the
         * portal showed only totals. An invoice screen and a printable
         * document need what was bought, for which period, at what unit price,
         * and the name and address the document is addressed to — and a
         * fixture without them lets a blank document look finished.
         */
        $this->invoiceWithLines($customer, [
            'number' => self::OPEN_INVOICE_NUMBER,
            'status' => InvoiceStatus::Open,
            'amount_paid_minor' => 0,
            'issued_at' => now()->subDays(2),
            'due_at' => now()->addDays(12),
        ], Money::of('9.000', 'KWD'), 'Cloud VPS — Starter (monthly)');

        $this->invoiceWithLines($customer, [
            'number' => self::LARGE_INVOICE_NUMBER,
            'status' => InvoiceStatus::Open,
            'amount_paid_minor' => 0,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addDays(20),
        ], Money::of('40.000', 'KWD'), 'Dedicated server — monthly rental');

        /*
         * Bigger than the seeded wallet balance, so paying it from credit
         * leaves a remainder for a card — which is the mixed payment the
         * portal has to be able to show as two rows.
         */
        $this->invoiceWithLines($customer, [
            'number' => self::CREDIT_THEN_CARD_INVOICE_NUMBER,
            'status' => InvoiceStatus::Open,
            'amount_paid_minor' => 0,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addDays(20),
        ], Money::of('40.000', 'KWD'), 'Dedicated server — monthly rental');

        foreach ([
            self::DECLINE_INVOICE_NUMBER,
            self::PHONE_PAYMENT_INVOICE_NUMBER,
            self::ARABIC_DECLINE_INVOICE_NUMBER,
        ] as $number) {
            $this->invoiceWithLines($customer, [
                'number' => $number,
                'status' => InvoiceStatus::Open,
                'amount_paid_minor' => 0,
                'issued_at' => now()->subDays(2),
                'due_at' => now()->addDays(12),
            ], Money::of('9.000', 'KWD'), 'Cloud VPS — Starter (monthly)');
        }

        $this->invoiceWithLines($customer, [
            'number' => self::PAID_INVOICE_NUMBER,
            'status' => InvoiceStatus::Paid,
            'amount_paid_minor' => 25500,
            'issued_at' => now()->subMonth(),
            'due_at' => now()->subMonth()->addDays(14),
            'paid_at' => now()->subMonth()->addDay(),
        ], Money::of('25.500', 'KWD'), 'Cloud VPS — Starter (monthly)');
    }

    /**
     * One invoice, its single line, and the billing details the document is
     * addressed to.
     *
     * The line is the whole invoice: one plan, one period, no tax — Kuwait's
     * rate is zero — so the document adds up exactly, which is the only way a
     * printed page is worth looking at.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoiceWithLines(
        Customer $customer,
        array $attributes,
        Money $total,
        string $description,
    ): void {
        $invoice = Invoice::factory()->for($customer)->totalling($total)->create($attributes + [
            'billing_snapshot' => [
                'customer_id' => (string) $customer->getKey(),
                'type' => $customer->type->value,
                'display_name' => $customer->display_name,
                'legal_name' => $customer->legal_name,
                'tax_id' => $customer->tax_id,
                'billing_email' => $customer->billing_email,
                'billing_phone' => $customer->billing_phone,
                'address' => [
                    'line1' => $customer->address_line1,
                    'line2' => $customer->address_line2,
                    'city' => $customer->city,
                    'state' => $customer->state,
                    'postal_code' => $customer->postal_code,
                    'country' => $customer->country,
                ],
                'captured_at' => ($attributes['issued_at'] ?? now())->toIso8601String(),
            ],
        ]);

        $invoice->items()->create([
            'kind' => InvoiceItemKind::Plan,
            'description' => $description,
            'quantity' => 1,
            'unit_amount_minor' => $total->minorUnits(),
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => $total->minorUnits(),
            'tax_rate' => '0',
            'tax_name' => null,
            'period_start' => $attributes['issued_at'] ?? now(),
            'period_end' => ($attributes['issued_at'] ?? now())->copy()->addMonth(),
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

    /**
     * Two credential references for the Control Center specs.
     *
     * The first names a variable the browser suite's API process is given
     * (see playwright.config.ts), so the screen reports it present; the
     * second names one it is not, so the screen reports it missing. Neither
     * row holds a value — there is no column for one.
     */
    private function credentials(): void
    {
        CredentialReference::query()->updateOrCreate(
            ['name' => 'e2e-registrar-key'],
            [
                'purpose' => 'Registrar API',
                'environment' => DeploymentEnvironment::Staging,
                'backend' => 'controller_environment',
                'backend_reference' => 'LYNOMIA_E2E_REGISTRAR_SECRET',
                'state' => CredentialState::Configured,
                'masked_hint' => 'Q7X2',
            ],
        );

        CredentialReference::query()->updateOrCreate(
            ['name' => 'e2e-bmc-password'],
            [
                'purpose' => 'BMC of the rack A chassis',
                'environment' => DeploymentEnvironment::Staging,
                'backend' => 'controller_environment',
                'backend_reference' => 'LYNOMIA_E2E_BMC_SECRET',
                'state' => CredentialState::Missing,
            ],
        );
    }

    /**
     * Three licences for the Control Center specs: one comfortably in force,
     * one within the thirty-day window, one already lapsed. All by the
     * calendar, so the states the screen shows are the ones the sweep would
     * compute.
     */
    private function licences(): void
    {
        foreach ([
            ['product' => 'cpanel', 'licence_type' => 'admin', 'expires_on' => now()->addYear(), 'state' => LicenceState::Active, 'external_reference' => 'E2E-ORDER-1'],
            ['product' => 'directadmin', 'licence_type' => 'standard', 'expires_on' => now()->addDays(12), 'state' => LicenceState::Expiring, 'external_reference' => 'E2E-ORDER-2'],
            ['product' => 'litespeed', 'licence_type' => null, 'expires_on' => now()->subDays(3), 'state' => LicenceState::Expired, 'external_reference' => 'E2E-ORDER-3'],
        ] as $licence) {
            Licence::query()->updateOrCreate(
                ['external_reference' => $licence['external_reference']],
                $licence + ['environment' => DeploymentEnvironment::Staging, 'starts_on' => now()->subMonths(6), 'seats' => 50],
            );
        }
    }

    /**
     * Two machines and two providers for the Control Center specs.
     *
     * The first machine is classified discovery_only with a controlled BMC
     * bound to it and the present credential attached, so a spec can test and
     * discover it. The second is untouched — do_not_touch, nothing bound —
     * which is what every machine looks like on the day it arrives, and what
     * the refusals are proven against. The DNS provider is registered and
     * nothing more, so the screen shows what it is waiting for.
     */
    /**
     * A second super-admin. A plan is not approved by the person who planned
     * it, so the browser suite needs two people who may approve.
     */
    /**
     * One rack in the seeded datacenter, so the site screen has a row and a
     * name that already exists to refuse a duplicate of.
     */
    private function rack(): void
    {
        $datacenter = Datacenter::query()->orderBy('created_at')->firstOrFail();

        Rack::query()->updateOrCreate(
            ['datacenter_id' => $datacenter->getKey(), 'name' => 'E2E-R1'],
            ['row' => 'A', 'units' => 42, 'power_notes' => 'Feed A/B from PDU-1', 'network_notes' => 'sw-1 ports 1-24'],
        );
    }

    private function secondOperator(): void
    {
        $second = User::firstOrCreate(
            ['email' => 'ops2@lynomia.local'],
            [
                'name' => 'Second Operator',
                'password' => 'password',
                'email_verified_at' => Date::now(),
                'password_changed_at' => Date::now(),
            ],
        );
        $second->syncRoles([Role::SuperAdmin->value]);
    }

    private function machinesAndProviders(): void
    {
        $present = CredentialReference::query()->where('name', 'e2e-registrar-key')->firstOrFail();

        $reachable = ManagedServer::query()->updateOrCreate(
            ['name' => 'e2e-node-01'],
            [
                'environment' => DeploymentEnvironment::Staging,
                'safety_class' => SafetyClass::DiscoveryOnly,
                'safety_reason' => 'Seeded for the browser suite.',
                'allow_reimage' => false,
                'vendor' => 'Fabrikam',
                'model' => 'FX-2200',
                'management_address' => 'fake://connected',
                'bmc_address' => 'fake://connected',
                'credential_reference_id' => $present->getKey(),
            ],
        );

        $bmc = ProviderInstance::query()->updateOrCreate(
            ['name' => 'e2e-bmc-node-01'],
            [
                'category' => ProviderCategory::Bmc,
                'driver' => 'fake_bmc',
                'environment' => DeploymentEnvironment::Staging,
                'endpoint' => 'fake://connected',
                'managed_server_id' => $reachable->getKey(),
                'credential_reference_id' => $present->getKey(),
            ],
        );

        ManagedServer::query()->updateOrCreate(
            ['name' => 'e2e-node-02'],
            [
                'environment' => DeploymentEnvironment::Staging,
                'safety_class' => SafetyClass::DoNotTouch,
                'allow_reimage' => false,
                'management_address' => '10.66.0.2',
            ],
        );

        // A machine the execution chain may be walked on: configurable,
        // reachable through the fake controller, nothing installed yet.
        ManagedServer::query()->updateOrCreate(
            ['name' => 'e2e-node-03'],
            [
                'environment' => DeploymentEnvironment::Staging,
                'safety_class' => SafetyClass::ConfigurationAllowed,
                'safety_reason' => 'Seeded for the browser suite: a lab machine the chain may be rehearsed on.',
                'allow_reimage' => false,
                'vendor' => 'Fabrikam',
                'model' => 'FX-1100',
                'management_address' => 'fake://connected',
            ],
        );

        $dns = ProviderInstance::query()->updateOrCreate(
            ['name' => 'e2e-dns'],
            [
                'category' => ProviderCategory::Dns,
                'driver' => 'fake',
                'environment' => DeploymentEnvironment::Staging,
                'endpoint' => 'fake://connected',
            ],
        );

        // Assessed the way registration assesses, so the rows carry the
        // blocker the screen is expected to name. A provider written straight
        // to the table has no verdict at all, which is a state the API never
        // produces and the browser suite must not be built against.
        $assess = app(AssessProvider::class);
        $assess->execute($bmc);
        $assess->execute($dns);
    }
}
