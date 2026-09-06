<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Application\Actions\ReserveHostingNodeCapacity;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Place it, take the slot, create it, record it — and classify every failure.
 *
 * The classification is not diagnostic decoration: it decides whether the
 * engine may try again and whether the resources the attempt reserved may be
 * handed back. Getting it wrong is how a customer ends up with two accounts,
 * or with none and no refund.
 */
final class CreateHostingAccountHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private HostingPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);

        $this->customer = Customer::factory()->create();
        $this->package = HostingPackage::factory()->named('lyn_starter')->create();
    }

    #[Test]
    public function it_places_takes_a_slot_creates_the_account_and_records_it(): void
    {
        $node = $this->node();

        $result = $this->handle($this->job(['username' => 'acmeone']));

        $this->assertTrue($result->successful);
        // Shared hosting has no asynchronous task handle: the panel does the
        // work before it answers, so the account name is the only handle there
        // is and stands as both.
        $this->assertSame('acmeone', $result->providerReference);
        $this->assertSame('acmeone', $result->remoteJobId);

        $account = HostingAccount::query()->where('username', 'acmeone')->sole();
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame((string) $node->getKey(), $account->hosting_node_id);

        // The slot was committed, once.
        $this->assertSame(1, $node->fresh()?->account_count);
    }

    #[Test]
    public function a_full_fleet_is_a_capacity_failure_rather_than_a_permanent_one(): void
    {
        /*
         * A full fleet is a condition that resolves — accounts are terminated,
         * an operator adds a node, a disk is grown — so the job waits and
         * retries rather than refunding a customer who would happily have
         * waited an hour.
         */
        $this->node(['disk_used_mib' => 2_000_000]);

        $result = $this->handle($this->job());

        $this->assertTrue($result->isFailure());
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
        $this->assertSame('hosting.no_capacity_available', $result->errorCode);
        $this->assertTrue($result->failureClass->isAutomaticallyRetryable());
    }

    #[Test]
    public function a_node_whose_licence_lapsed_is_a_capacity_failure_so_the_job_retries_elsewhere(): void
    {
        /*
         * A lapsed licence is PERMANENT for the node and retryable for the job,
         * and Capacity is the class that expresses exactly that pair.
         *
         * Permanent would be true of the node — cPanel is a commercial product
         * and an unlicensed panel does not serve, so no retry against this
         * machine can succeed — but Permanent is a statement about the JOB. It
         * would fail the order outright and refund a customer whom every other
         * licensed node could have served in seconds.
         *
         * Transient would retry, but it says "the provider was momentarily
         * unavailable", which hides a licensing problem behind an outage and
         * delays the one action that fixes it.
         */
        $node = $this->node(['panel' => HostingPanel::Cpanel]);

        // Licensed when the scheduler looked; lapsed by the time the worker
        // picked the job up. A licence expires on a date, not on an event.
        $node->forceFill(['panel_licensed' => false, 'licence_status' => 'expired'])->saveQuietly();

        $result = $this->handle($this->job());

        $this->assertTrue($result->isFailure());
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
        $this->assertTrue(
            $result->failureClass->isAutomaticallyRetryable(),
            'The job must be retryable so it can land on a licensed node.',
        );
    }

    #[Test]
    public function the_unlicensed_failure_names_the_licence_so_an_operator_knows_to_renew(): void
    {
        // Capacity is the retry policy; the error code is what tells the
        // operator that waiting will not help.
        $this->node(['panel' => HostingPanel::Cpanel])
            ->forceFill(['panel_licensed' => false, 'licence_status' => 'expired'])
            ->saveQuietly();

        $result = $this->handle($this->job());

        $this->assertSame('hosting.no_capacity_available', $result->errorCode);
        $this->assertStringContainsString('unlicensed=1', (string) ($result->metadata['rejections'] ?? ''));
    }

    #[Test]
    public function a_timeout_is_classified_as_timeout_and_never_retried(): void
    {
        /*
         * The rule the whole platform is built on. WHM builds a home
         * directory, a mail store, a database user and a DNS zone before it
         * answers, so a create the platform stopped waiting for may well have
         * been accepted. Retrying is how a customer ends up with two accounts.
         */
        $this->node();

        $result = $this->handle($this->job(['username' => 'acme-timeout']));

        $this->assertTrue($result->isFailure());
        $this->assertSame(FailureClass::Timeout, $result->failureClass);
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());
        $this->assertTrue($result->failureClass->requiresQuarantine());
        $this->assertTrue($result->failureClass->requiresReview());

        // The name under which the account may exist is the single most
        // valuable fact after a timeout, and it is carried on the failure.
        $this->assertSame('acme-timeout', $result->providerReference);
    }

    #[Test]
    public function a_timeout_leaves_the_pending_row_and_the_slot_alone(): void
    {
        // The account may exist. Marking the row failed or releasing the slot
        // would hand the node's capacity to somebody else while a real account
        // is sitting on it.
        $node = $this->node();

        $this->handle($this->job(['username' => 'acme-timeout']));

        $account = HostingAccount::query()->where('username', 'acme-timeout')->sole();
        $this->assertSame(HostingAccountStatus::Pending, $account->status);
        $this->assertSame(1, $node->fresh()?->account_count);
    }

    #[Test]
    public function a_refusal_the_panel_spoke_out_loud_is_transient_and_marks_the_row_failed(): void
    {
        // The panel answered and created nothing, so the pending row is a lie.
        $this->node();

        // Exactly 16 characters, so the marker survives the panel's own
        // username limit rather than being truncated away.
        $result = $this->handle($this->job(['username' => 'ac-provider-fail']));

        $this->assertSame(FailureClass::Transient, $result->failureClass);
        $this->assertTrue($result->failureClass->isAutomaticallyRetryable());

        $account = HostingAccount::query()->where('username', 'ac-provider-fail')->sole();
        $this->assertSame(HostingAccountStatus::Failed, $account->status);
    }

    #[Test]
    public function a_refusal_the_panel_spoke_out_loud_gives_the_nodes_slot_back(): void
    {
        /*
         * The panel answered and created nothing, so the node is genuinely one
         * account emptier than its count says — and nothing else will ever
         * correct it. The engine's compensation reaches only what the bound
         * ResourceReservationReleaser knows about, which is IP reservations;
         * a hosting slot not released here is never released at all, and the
         * node quietly stops accepting accounts long before its disk is full.
         */
        $node = $this->node();

        $result = $this->handle($this->job(['username' => 'ac-provider-fail']));

        $this->assertSame(FailureClass::Transient, $result->failureClass);
        $this->assertSame(0, $node->fresh()?->account_count);
    }

    #[Test]
    public function a_retry_after_a_released_slot_takes_the_slot_again(): void
    {
        /*
         * The other half of the release. A transient failure is retryable, and
         * a retry that found its old row and carried on WITHOUT re-committing a
         * slot would put an active account onto a count that was never
         * incremented — the node oversubscribed by one for every such retry,
         * and only discovered when the machine runs out of disk.
         */
        $node = $this->node();

        $this->handle($this->job(['username' => 'ac-provider-fail']));
        $this->assertSame(0, $node->fresh()?->account_count);

        $reservation = app(ReserveHostingNodeCapacity::class)->reserve(
            node: $node->fresh() ?? $node,
            username: 'ac-provider-fail',
            primaryDomain: 'acme.example.test',
            customerId: (string) $this->customer->getKey(),
            package: $this->package,
        );

        $this->assertTrue($reservation->slotTakenNow);
        $this->assertSame(HostingAccountStatus::Pending, $reservation->account->status);
        $this->assertSame(1, $node->fresh()?->account_count);
        // One identity for the account across every attempt: the unique index
        // on (hosting_node_id, username) is the idempotency key.
        $this->assertSame(1, HostingAccount::query()->where('username', 'ac-provider-fail')->count());
    }

    #[Test]
    public function a_retried_job_never_marks_a_live_account_failed(): void
    {
        /*
         * The panel refuses a duplicate by name, and that refusal says nothing
         * about the account already sitting there — it IS that account. A
         * handler that recorded the refusal against the existing row would take
         * a live, serving, billable account out of the platform's books: it
         * would stop occupying capacity, stop being billed and never be
         * terminated, while the customer's site carried on being served.
         */
        $node = $this->node();
        $job = $this->job(['username' => 'acmeone']);

        $this->handle($job);
        $second = $this->handle($job);

        $this->assertTrue($second->isFailure());

        $account = HostingAccount::query()->where('username', 'acmeone')->sole();
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        // And the slot the live account is using is not handed to anybody else.
        $this->assertSame(1, $node->fresh()?->account_count);
    }

    #[Test]
    public function a_refusal_against_a_reservation_left_by_a_timed_out_attempt_releases_nothing(): void
    {
        /*
         * The timeout rule, one step removed. An attempt that timed out left a
         * pending row and may well have created the account; a later attempt
         * that is refused with "this account already exists" must not read that
         * as proof the node is holding nothing. Releasing the slot there would
         * be releasing after a timeout by another route, and the disk it hands
         * out is disk a real customer's files are on.
         */
        $node = $this->node();

        // The pending row a timed-out attempt leaves behind.
        app(ReserveHostingNodeCapacity::class)->reserve(
            node: $node,
            username: 'acmeone',
            primaryDomain: 'acmeone.example.test',
            customerId: (string) $this->customer->getKey(),
            package: $this->package,
        );

        $reservation = app(ReserveHostingNodeCapacity::class)->reserve(
            node: $node->fresh() ?? $node,
            username: 'acmeone',
            primaryDomain: 'acmeone.example.test',
            customerId: (string) $this->customer->getKey(),
            package: $this->package,
        );

        $this->assertFalse($reservation->slotTakenNow);
        $this->assertSame(1, $node->fresh()?->account_count);
    }

    #[Test]
    public function a_job_naming_a_package_that_does_not_exist_fails_permanently(): void
    {
        // It will name the same non-existent package on every retry; retrying
        // wastes a worker and delays the operator finding out.
        $this->node();

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $this->customer->getKey(),
            'payload' => ['hosting_package_id' => '01JBQ8ZK4M3N5P7R9T1V3W5XZZ'],
        ]);

        $result = $this->handle($job);

        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('hosting.unknown_package', $result->errorCode);
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());
    }

    #[Test]
    public function a_retried_job_does_not_take_a_second_slot(): void
    {
        /*
         * A worker killed after the panel accepted a create comes back and runs
         * the job from the top. The pending row is its own idempotency key.
         */
        $node = $this->node();
        $job = $this->job(['username' => 'acmeone']);

        $this->handle($job);
        $second = $this->handle($job);

        // The panel refuses a duplicate by name, which is the behaviour the
        // platform relies on rather than something it has to prevent itself.
        $this->assertTrue($second->isFailure());
        $this->assertSame(1, $node->fresh()?->account_count);
        $this->assertSame(1, HostingAccount::query()->where('username', 'acmeone')->count());
    }

    #[Test]
    public function the_username_is_truncated_to_the_panels_own_limit(): void
    {
        // cPanel silently shortens a name it considers too long, and two
        // customers whose names shorten to the same string would be one
        // account. Doing it here means the platform knows the name it will get.
        $node = $this->node(['panel' => HostingPanel::Cpanel]);
        app(HostingProviderFactory::class)->swap($node, new FakeHostingProvider);

        $result = $this->handle($this->job(['username' => 'averyverylongcustomername']));

        $this->assertSame(HostingPanel::Cpanel->maxUsernameLength(), strlen((string) $result->providerReference));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function node(array $attributes = []): HostingNode
    {
        return HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function job(array $payload = []): ProvisioningJob
    {
        return ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $this->customer->getKey(),
            'payload' => [
                'hosting_package_id' => (string) $this->package->getKey(),
                'primary_domain' => 'acme.example.test',
                'password' => 's3cret-panel-password',
                'contact_email' => 'owner@acme.example.test',
                ...$payload,
            ],
        ]);
    }

    private function handle(ProvisioningJob $job): ProvisioningResult
    {
        return app(CreateHostingAccountHandler::class)->execute($job);
    }
}
