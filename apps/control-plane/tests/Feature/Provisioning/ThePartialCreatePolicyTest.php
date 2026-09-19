<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Application\Queries\CustomerServices;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the platform says about a service whose build did not finish cleanly.
 *
 * ===========================================================================
 * The question this file settles
 * ===========================================================================
 *
 * Gap 7 found a service that read `active` after its create task failed: the
 * hypervisor accepted the request, the platform wrote the machine row and
 * activated the service on the strength of that acceptance, and the task then
 * ended in failure. The job went to `needs_review`, a Critical drift was
 * recorded — its own text says "the platform is billing for, and showing the
 * customer, a machine whose build the hypervisor says did not finish" — and
 * the customer's service list went on saying `active`.
 *
 * It was carried as a PRODUCT DECISION because deciding it needed an answer to
 * "what does Active mean here", and the repository does answer that:
 * `ServiceStatus::isUsable()` is true for `Active` and nothing else, and
 * `Reactivating` exists precisely because "the payment succeeded" and "the
 * machine is back" are different facts. `Active` is a claim about usability.
 *
 * ===========================================================================
 * The policy
 * ===========================================================================
 *
 * `services.status` keeps meaning what the state machine says it means: what
 * the customer bought and owes for. It is not rewritten from a job outcome,
 * which is what stops a failed reboot from erasing a machine's own history.
 *
 * What changes is the word the customer is given. A job in `needs_review`
 * whose kind CREATED the service eclipses the service's state, because the
 * delivery of the thing they own is what is in doubt. A job in `needs_review`
 * of any other kind does not: a failed reboot of a running server is not a
 * failed server.
 *
 * The four partial-create cases, and where each lands:
 *
 * | what is true at the provider | job | services.status | customer reads | who acts |
 * |---|---|---|---|---|
 * | definitely absent (refused before anything was built) | `queued`, retried, then `failed` | `failed` | `under_review` while stuck, `failed` after | the engine, then a person |
 * | definitely exists (task confirmed) | `succeeded` | `active` | `active` | nobody |
 * | may exist (the platform stopped waiting) | `needs_review`, timeout, no compensation | unchanged | `under_review` | a person |
 * | exists but finalisation failed (task reported failure) | `needs_review`, permanent, Critical drift | unchanged | `under_review` | a person |
 *
 * The last two share a row in everything a customer sees, and that is
 * deliberate: from their side "we do not know whether this works" and "this
 * was built and the build failed" call for the same message and the same
 * absence of a button.
 */
final class ThePartialCreatePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every case of the table above, as the enum answers it.
     *
     * @return iterable<string, array{0: ServiceStatus, 1: ProvisioningJobKind, 2: CustomerServiceState}>
     */
    public static function partialCreateCases(): iterable
    {
        yield 'a confirmed build is active' => [
            ServiceStatus::Active,
            // No stuck job at all; the kind is irrelevant and the review
            // counts below are zero for this case.
            ProvisioningJobKind::CreateVps,
            CustomerServiceState::Active,
        ];

        yield 'a create that may or may not have built something hides active' => [
            ServiceStatus::Active,
            ProvisioningJobKind::CreateVps,
            CustomerServiceState::UnderReview,
        ];

        yield 'a stuck hosting create hides active too' => [
            ServiceStatus::Active,
            ProvisioningJobKind::CreateHostingAccount,
            CustomerServiceState::UnderReview,
        ];

        yield 'a stuck dedicated provision hides active too' => [
            ServiceStatus::Active,
            ProvisioningJobKind::ProvisionDedicated,
            CustomerServiceState::UnderReview,
        ];

        yield 'a stuck reboot leaves a working machine alone' => [
            ServiceStatus::Active,
            ProvisioningJobKind::Restart,
            CustomerServiceState::Active,
        ];

        yield 'a stuck reinstall leaves a working machine alone' => [
            ServiceStatus::Active,
            ProvisioningJobKind::ReinstallVps,
            CustomerServiceState::Active,
        ];

        yield 'a stuck create during the build still reads under review' => [
            ServiceStatus::Provisioning,
            ProvisioningJobKind::CreateVps,
            CustomerServiceState::UnderReview,
        ];

        yield 'a terminated service is terminated whatever happened to its build' => [
            ServiceStatus::Terminated,
            ProvisioningJobKind::CreateVps,
            CustomerServiceState::Terminated,
        ];
    }

    #[Test]
    #[DataProvider('partialCreateCases')]
    public function the_word_a_customer_is_given_follows_the_policy(
        ServiceStatus $status,
        ProvisioningJobKind $kind,
        CustomerServiceState $expected,
    ): void {
        $nothingIsStuck = $expected === CustomerServiceState::Active
            && $kind->createsResource()
            && $status === ServiceStatus::Active;

        $this->assertSame($expected, CustomerServiceState::for(
            $status,
            isAwaitingReview: ! $nothingIsStuck,
            deliveryIsInDoubt: ! $nothingIsStuck && $kind->createsResource(),
        ));
    }

    #[Test]
    public function an_active_service_whose_create_is_in_review_is_published_as_under_review(): void
    {
        /*
         * The same property through the query and the serialiser rather than
         * through the enum, because that is where the customer reads it: the
         * counted columns, the resource, and the filter all have to agree.
         */
        [$customer, $service] = $this->activeServiceWithStuckJob(ProvisioningJobKind::CreateVps);

        $published = $this->publishedStateFor($customer, $service);

        $this->assertSame(CustomerServiceState::UnderReview->value, $published);
    }

    #[Test]
    public function an_active_service_whose_reboot_is_in_review_is_still_published_as_active(): void
    {
        // The positive twin. A stuck operational job must not make a working
        // machine read as one nobody can rely on.
        [$customer, $service] = $this->activeServiceWithStuckJob(ProvisioningJobKind::Restart);

        $this->assertSame(
            CustomerServiceState::Active->value,
            $this->publishedStateFor($customer, $service),
        );
    }

    #[Test]
    public function filtering_by_active_does_not_return_a_service_the_list_prints_as_under_review(): void
    {
        /*
         * The failure this guards against is a list that disagrees with
         * itself: `?state=active` returning a row whose own `state` field says
         * `under_review`. One definition of "eclipsed" is derived from the
         * enum by both sides, so the two cannot drift.
         */
        [$customer, $stuck] = $this->activeServiceWithStuckJob(ProvisioningJobKind::CreateVps);

        $healthy = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        $active = CustomerServices::inState(
            CustomerServices::of($customer->fresh()),
            CustomerServiceState::Active,
        )->pluck('id')->all();

        $this->assertContains($healthy->getKey(), $active);
        $this->assertNotContains($stuck->getKey(), $active);

        $underReview = CustomerServices::inState(
            CustomerServices::of($customer->fresh()),
            CustomerServiceState::UnderReview,
        )->pluck('id')->all();

        $this->assertContains($stuck->getKey(), $underReview);
        $this->assertNotContains($healthy->getKey(), $underReview);
    }

    /**
     * @return array{0: Customer, 1: Service}
     */
    private function activeServiceWithStuckJob(ProvisioningJobKind $kind): array
    {
        $customer = Customer::factory()->create();

        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'kind' => $kind,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Permanent,
            'provider' => 'fake',
        ]);

        return [$customer, $service];
    }

    private function publishedStateFor(Customer $customer, Service $service): string
    {
        $loaded = CustomerServices::of($customer)->whereKey($service->getKey())->sole();

        return CustomerServiceState::for(
            $loaded->status,
            is_numeric($pending = $loaded->getAttribute(CustomerServices::REVIEWS_PENDING))
                && (int) $pending > 0,
            is_numeric($delivery = $loaded->getAttribute(CustomerServices::DELIVERY_REVIEWS_PENDING))
                && (int) $delivery > 0,
        )->value;
    }
}
