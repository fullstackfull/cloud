<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
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

    /** An invoice that is payable, so the pay button has something to do. */
    public const string OPEN_INVOICE_NUMBER = 'INV-E2E-0001';

    /** One that is settled, so the two states can be told apart on screen. */
    public const string PAID_INVOICE_NUMBER = 'INV-E2E-0002';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'E2ESeeder must never run in production: it creates fixtures with fixed identifiers.'
            );
        }

        $customer = Customer::query()->where('billing_email', 'customer@lynomia.local')->firstOrFail();

        $this->virtualMachine($customer);
        $this->invoices($customer);
        $this->wallet($customer);

        $this->announce(sprintf(
            'E2E fixtures seeded: machine %s, invoices %s and %s.',
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

        // One of each state the backups screen has to render differently: a
        // finished one the customer could restore from, and one that stopped
        // being trackable and is waiting for a person.
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
