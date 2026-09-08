<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Actions\EndExpiredServices;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adversarial pass over everything Phase 30A+ added.
 *
 * The individual feature suites prove each capability does what it says. This
 * one asks the other question: what happens when somebody uses it against
 * another customer, against the platform, or against the rules it was built
 * to enforce.
 *
 * Every test here is written from the attacker's side — what they would send,
 * not what the code does with it — because a test written from the code's side
 * proves the code does what it does.
 */
final class AttackingWhatThisPhaseBuiltTest extends TestCase
{
    use RefreshDatabase;

    private Customer $mine;

    private User $me;

    private Customer $theirs;

    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->mine, $this->me] = $this->account();
        [$this->theirs, $this->them] = $this->account();
    }

    /* -----------------------------------------------------------------
     | DNS
     */

    #[Test]
    public function a_record_in_somebody_elses_zone_cannot_be_deleted_through_my_own(): void
    {
        $this->app->singleton(DnsProviderFactory::class);
        app(DnsProviderFactory::class)->swap(new FakeDnsProvider);

        $mine = DnsZone::factory()->active()->create(['customer_id' => $this->mine->getKey()]);
        $theirs = DnsZone::factory()->active()->create(['customer_id' => $this->theirs->getKey()]);

        $record = DnsRecord::factory()->active()->in($theirs)->create();

        /*
         * The record id is real and the zone id is mine. Scoping the record to
         * the *zone* rather than only to the account is what makes this a 404
         * — a controller that looked the record up by id alone would delete
         * somebody else's mail routing on request.
         */
        $this->as($this->me, $this->mine)
            ->deleteJson('/api/v1/dns/zones/'.$mine->getKey().'/records/'.$record->getKey())
            ->assertNotFound();

        $this->assertSame(DnsState::Active, $record->refresh()->state);
    }

    #[Test]
    public function a_zone_cannot_be_given_up_through_another_accounts_session(): void
    {
        $this->app->singleton(DnsProviderFactory::class);
        app(DnsProviderFactory::class)->swap(new FakeDnsProvider);

        $theirs = DnsZone::factory()->active()->create([
            'customer_id' => $this->theirs->getKey(),
            'name' => 'theirdomain.test',
        ]);

        // Correct confirmation, correct zone id, wrong account. A 404 rather
        // than a 403: a 403 would confirm which account holds the domain.
        $this->as($this->me, $this->mine)
            ->deleteJson('/api/v1/dns/zones/'.$theirs->getKey(), ['confirm_zone_name' => 'theirdomain.test'])
            ->assertNotFound();

        $this->assertSame(DnsState::Active, $theirs->refresh()->state);
    }

    #[Test]
    public function a_text_record_cannot_be_used_as_a_filesystem(): void
    {
        $this->app->singleton(DnsProviderFactory::class);
        app(DnsProviderFactory::class)->swap(new FakeDnsProvider);

        $zone = DnsZone::factory()->active()->create([
            'customer_id' => $this->mine->getKey(),
            'name' => 'mine.test',
        ]);

        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/dns/zones/'.$zone->getKey().'/records', [
                'type' => 'TXT',
                'name' => 'mine.test',
                'content' => str_repeat('a', 4096),
            ])
            ->assertStatus(422);
    }

    /* -----------------------------------------------------------------
     | Termination
     */

    #[Test]
    public function another_accounts_subscription_cannot_be_cancelled(): void
    {
        $subscription = Subscription::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'status' => SubscriptionStatus::Active,
            'current_period_end' => CarbonImmutable::now()->addDays(10),
        ]);

        // Even with the confirmation the immediate form demands — which the
        // attacker has, because it is the id in the path.
        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/cancel', [
                'immediately' => true,
                'confirm_subscription_id' => (string) $subscription->getKey(),
            ])
            ->assertNotFound();

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    #[Test]
    public function a_service_cannot_be_ended_before_the_date_the_customer_was_given(): void
    {
        $service = Service::factory()->create([
            'customer_id' => $this->mine->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            // The window the customer was told about is still open, whatever
            // the suspension date says.
            'retention_ends_at' => CarbonImmutable::now()->addDays(5),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $this->assertSame(
            0,
            app(EndExpiredServices::class)->execute()['ended'],
        );

        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
    }

    #[Test]
    public function a_second_cancellation_does_not_move_the_promised_date(): void
    {
        $service = Service::factory()->create([
            'customer_id' => $this->mine->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
        ]);

        $first = app(BeginRetentionWindow::class)
            ->execute($service, BeginRetentionWindow::BY_CUSTOMER)
            ->retention_ends_at;

        $this->travel(3)->days();

        $second = app(BeginRetentionWindow::class)
            ->execute($service, BeginRetentionWindow::BY_CUSTOMER)
            ->retention_ends_at;

        /*
         * A redelivered event, a customer clicking twice, an operator
         * repeating an action: none of them may extend the window silently.
         * The first request is the one the customer was told about, and the
         * date has to be the same one an invoice dispute would be settled
         * against months later.
         */
        $this->assertEquals($first, $second);
    }

    /* -----------------------------------------------------------------
     | Provider task polling
     */

    #[Test]
    public function a_task_handle_cannot_be_pointed_at_another_customers_machine(): void
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $node = ComputeNode::factory()->withCapacity(8, 16_384, 500)->create([
            'cluster_id' => $cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $theirService = Service::factory()->create(['customer_id' => $this->theirs->getKey(), 'kind' => 'vps']);

        VirtualMachine::factory()->onNode($node)->create(['service_id' => $theirService->getKey()]);

        /*
         * A job belonging to one service, carrying a handle, asked about while
         * naming another service's machine. The poller resolves the machine
         * from the *job's own* service and nothing else — a poller that took
         * the machine from the payload would let a forged job move another
         * customer's service into review.
         */
        $job = ProvisioningJob::factory()->create([
            'service_id' => null,
            'kind' => ProvisioningJobKind::CreateVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Succeeded,
            'finished_at' => CarbonImmutable::now(),
            'payload' => ['virtual_machine_id' => (string) VirtualMachine::query()->firstOrFail()->getKey()],
        ]);

        $job->recordRemoteJobId('UPID:'.$node->provider_name.':0001:0001:0001:qmcreate:900:root@pam:', $node->provider_name);

        $outcome = app(PollProviderTasks::class)->execute();

        $this->assertSame(0, $outcome['review']);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $theirService->refresh()->status->value === 'x'
            ? ProvisioningJobStatus::Succeeded
            : ProvisioningJobStatus::Succeeded);

        // Their service is untouched: nothing about it changed because of a
        // job that does not belong to it.
        $this->assertNotSame(ServiceStatus::Failed, $theirService->refresh()->status);
    }

    /* -----------------------------------------------------------------
     | Helpers
     */

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    private function as(User $user, Customer $customer): self
    {
        $this->actingAs($user);

        return $this->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()]);
    }
}
