<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The gate for a defect this phase caused, and then had to bisect to find.
 *
 * Nothing in this directory or in `tests/Feature/Queue` uses
 * RefreshDatabase — it cannot, because a transaction is invisible to the
 * worker process that is the entire point. The rows are committed, so they
 * outlive the test unless the teardown removes them, and the first version of
 * that teardown removed only the models a `created` event in *this* process
 * had announced.
 *
 * A worker in another process announces nothing here. `FulfilOrderOnSettlement`
 * → `ProvisionOrderedService` writes the provisioning job inside the worker,
 * and that row's foreign keys are not all cascading, so it survived — and took
 * its cluster and node with it, because a parent cannot be deleted while a
 * child still points at it and the sweep stopped when a pass made no progress.
 *
 * What that cost: two files here left rows behind (`provisioning_jobs=1
 * compute_clusters=1 compute_nodes=1` and `compute_clusters=4
 * compute_nodes=4`), and twenty-nine assertions in `tests/Feature/Vps` failed
 * in the full run while passing on their own — `assertSame(0,
 * ProvisioningJob::query()->count())`, `->sole()` finding two rows,
 * idempotency replay counts reading two where one was expected. Every one of
 * those failures was in a file that had nothing to do with this gap.
 *
 * So the property is now asserted rather than inferred from bookkeeping: the
 * first test drives a paid order through two workers, and the second finds the
 * database empty. PHPUnit runs them in declaration order, so the second test
 * is reading exactly what the first one's teardown left behind.
 */
#[Group('golden-path')]
final class TheHarnessHandsBackAnEmptyDatabaseTest extends GoldenPathHarness
{
    private const string PAYMENTS_QUEUE = 'payments';

    /**
     * The tables a paid VPS order touches, on both sides of the process
     * boundary.
     *
     * @var list<string>
     */
    private const array ESTATE = [
        'customers',
        'orders',
        'order_items',
        'invoices',
        'provisioning_jobs',
        'hosting_nodes',
        'hosting_packages',
        'hosting_accounts',
        'plans',
        'products',
    ];

    #[Test]
    public function a_worker_writes_rows_this_process_never_announced(): void
    {
        /*
         * The shape of the path that actually leaked: a hosting account built
         * by a worker. The job row is written here, but what the worker does
         * with it — the account, the node's usage counters, the trail — is
         * written in the other process, and the FK graph left the job itself
         * undeletable by a reverse sweep.
         */
        $this->outsideTheTransaction(static fn (): HostingNode => HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]));

        $plan = $this->committedPlan(ProductKind::SharedHosting, monthlyMinor: 4_500);

        $this->outsideTheTransaction(fn (): HostingPackage => HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'panel_package_name' => 'lyn_isolation',
        ]));

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'harness-isolation');

        $job = $this->outsideTheTransaction(
            fn (): ProvisioningJob => app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::CreateHostingAccount,
                idempotencyKey: 'harness-isolation:'.$invoice->getKey(),
                provider: 'fake',
                serviceId: null,
                customerId: (string) $customer->getKey(),
                payload: [
                    'hosting_package_id' => (string) HostingPackage::query()
                        ->where('plan_id', $plan->getKey())->sole()->getKey(),
                    'username' => 'isolation1',
                    'primary_domain' => 'isolation1.example.test',
                    'password' => 'Pnl-isolation-91c4e70a2b',
                    'admin_password' => 'Wp-isolation-3e7a05cd16',
                    'contact_email' => 'owner@isolation1.example.test',
                ],
            )),
        );

        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        // Asserted so that the next test is known to have had something to
        // find: a run in which this build never happened would make the
        // emptiness below meaningless.
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('hosting_accounts')->count());
    }

    #[Test]
    public function and_the_next_test_finds_none_of_them(): void
    {
        foreach (self::ESTATE as $table) {
            $this->assertSame(
                0,
                DB::connection(self::CONNECTION)->table($table)->count(),
                sprintf('%s still holds rows the previous test committed.', $table),
            );
        }
    }
}
