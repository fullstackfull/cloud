<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Actions\SuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Shared hosting, and the WordPress install that rides on it.
 *
 * The same chain as the VPS path — money in this process, settlement and
 * fulfilment in one worker, the build in another — with the differences that
 * matter to this product:
 *
 *  - **The package is the catalogue's own mapping.** A plan names the quota a
 *    panel will enforce, and a job that does not name one is refused rather
 *    than guessed at. That refusal is why the fixture links the two.
 *  - **The node is chosen by the scheduler**, not by the plan, so the estate
 *    offers one node with room and the assertion is that the account landed on
 *    it and took a slot.
 *  - **WordPress is the same provider.** There is no WordPress server: the
 *    installer is the panel, which is the real relationship and the reason
 *    `fake_wordpress` and `fake_hosting` are the same simulator behind two
 *    catalogue entries.
 *
 * The end of the life is here too, because a hosting account is the family
 * whose teardown is fully implemented: suspended, then terminated, with the
 * panel's own state asserted after each.
 */
#[Group('golden-path')]
final class TheHostingGoldenPathTest extends GoldenPathHarness
{
    private const string PAYMENTS_QUEUE = 'payments';

    #[Test]
    public function a_paid_order_becomes_a_panel_account_that_can_be_suspended_and_terminated(): void
    {
        $node = $this->committedHostingNode();

        $plan = $this->committedPlan(ProductKind::SharedHosting, monthlyMinor: 4_500);

        $this->outsideTheTransaction(fn (): HostingPackage => HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'panel_package_name' => 'lyn_golden',
        ]));

        $customer = $this->committedCustomer();

        [$order, $invoice] = $this->orderAndInvoice($customer, $plan, 'golden-hosting-1');

        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_hosting_1');

        $this->work(self::PAYMENTS_QUEUE);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(ServiceStatus::Provisioning, $service->status);

        $job = ProvisioningJob::query()->where('service_id', $service->getKey())->sole();
        $this->assertSame(ProvisioningJobKind::CreateHostingAccount, $job->kind);

        // The package the plan names reached the payload, which is the thing
        // that used to be missing and left customers paying for an account the
        // worker refused to create.
        $this->assertSame(
            (string) HostingPackage::query()->where('plan_id', $plan->getKey())->sole()->getKey(),
            (string) ($job->payload['hosting_package_id'] ?? ''),
        );

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        $account = HostingAccount::query()->where('service_id', $service->getKey())->sole();

        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame((string) $node->getKey(), $account->hosting_node_id);
        $this->assertSame(1, $node->fresh()?->account_count, 'the node did not take a slot for the account');

        // ---- the panel's own view, in this process -------------------------
        $provider = app(HostingProviderFactory::class)->for($node);

        $remote = $provider->listAccounts($node);

        $this->assertCount(1, $remote);
        $this->assertSame($account->username, $remote[0]->username);
        $this->assertFalse($remote[0]->suspended);

        /*
         * ---- WordPress, on the account that already exists -----------------
         *
         * The site row first, then the install job that carries its id. That
         * ordering is the platform's own: a site is a thing a customer asked
         * for before it is a thing a panel has installed, and the handler
         * refuses a job whose site row does not exist rather than installing
         * something nobody can then find.
         *
         * The customer-facing purchase of a WordPress plan is a second order
         * and is proved in `TheWholeLifeOfAWordPressOrderTest`; what this path
         * adds is the install crossing a worker with the panel's state shared.
         */
        $install = $this->outsideTheTransaction(
            function () use ($account, $customer, $service): ProvisioningJob {
                $site = WordPressSite::factory()->create([
                    'customer_id' => $customer->getKey(),
                    'hosting_account_id' => $account->getKey(),
                    'service_id' => $service->getKey(),
                    'domain' => $account->primary_domain,
                    'admin_username' => 'golden-admin',
                ]);

                return app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                    kind: ProvisioningJobKind::InstallWordPress,
                    idempotencyKey: 'golden-hosting-wp:'.$service->getKey(),
                    provider: 'fake',
                    serviceId: (string) $service->getKey(),
                    customerId: (string) $customer->getKey(),
                    payload: [
                        'wordpress_site_id' => (string) $site->getKey(),
                        'admin_username' => 'golden-admin',
                        'admin_email' => 'golden@'.$account->primary_domain,
                        'locale' => 'en_US',
                    ],
                ));
            },
        );

        RunProvisioningJob::dispatch((string) $install->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $install->fresh()?->status);

        /*
         * The site is on the panel, read in this process. An installer is the
         * one operation whose timeout is genuinely indeterminate, so the fake
         * records the install *before* it can fail — and that is exactly why
         * this assertion is about the panel and not about the job's return
         * value.
         */
        $site = $provider->wordPressInstallation($node, $account->username, $account->primary_domain);

        $this->assertTrue($site->exists);
        $this->assertSame('https://'.$account->primary_domain, $site->siteUrl);

        // ---- suspension ----------------------------------------------------
        app(SuspendHostingAccount::class)->execute($account, 'golden path');

        $this->assertSame(HostingAccountStatus::Suspended, $account->fresh()?->status);
        $this->assertTrue($provider->listAccounts($node)[0]->suspended);

        // ---- termination ---------------------------------------------------
        app(TerminateHostingAccount::class)->execute($account->fresh(), force: true);

        $this->assertSame(HostingAccountStatus::Terminated, $account->fresh()?->status);
        $this->assertSame([], $provider->listAccounts($node));
        $this->assertSame(0, $node->fresh()?->account_count, 'the slot was not given back');
    }

    #[Test]
    public function a_plan_with_no_package_waits_for_an_operator_rather_than_failing_at_the_panel(): void
    {
        /*
         * §46: the root cause is a catalogue that is incomplete, and the
         * platform says so where somebody can fix it. No job is created at
         * all — a job with no package would reach a worker, be refused by the
         * panel, and read to a customer as an outage.
         *
         * The plan is sold with a package and loses it after the order. It has
         * to be: checkout now refuses a hosting plan that names no package, so
         * this order could never be placed in that state — which is the better
         * answer, and is proved elsewhere. What is left is the window that
         * rule cannot close, an operator retiring a package between a payment
         * and a build, and the platform's behaviour in it is unchanged.
         */
        $this->committedHostingNode();

        $plan = $this->committedPlan(ProductKind::SharedHosting, monthlyMinor: 4_500);

        $this->outsideTheTransaction(fn (): HostingPackage => HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'panel_package_name' => 'lyn_golden_withdrawn',
        ]));

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'golden-hosting-nopackage');

        $this->outsideTheTransaction(
            fn (): int => HostingPackage::query()->where('plan_id', $plan->getKey())->delete(),
        );

        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_hosting_nopackage');

        $this->work(self::PAYMENTS_QUEUE);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(0, ProvisioningJob::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(0, $this->queued(RunProvisioningJob::QUEUE));
        /*
         * And the reason is on the service, where an operator looking at the
         * customer's account will find it, rather than only in a log line.
         */
        $this->assertStringContainsString(
            'hosting package',
            (string) (($service->resources['placement_blocked_reason'] ?? '')),
        );
    }

    private function committedHostingNode(): HostingNode
    {
        return $this->outsideTheTransaction(static fn (): HostingNode => HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]));
    }
}
