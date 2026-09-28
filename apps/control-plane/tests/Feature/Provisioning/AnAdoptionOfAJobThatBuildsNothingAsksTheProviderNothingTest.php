<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Contracts\ReservationsFollowAnAdoption;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An adoption of a job that builds nothing is refused before the provider is
 * asked anything (AdoptOrphanResource::lookFor(): "Refused before the
 * provider is asked about anything").
 *
 * What the adoption asks of a provider it asks through
 * ReservationsFollowAnAdoption::lookFor(), before its transaction. The real
 * binding asks nothing for a job that is not a VPS build, so the order of the
 * kind check and that call could be swapped with every other test green (the
 * re-audit after round eight, band D). Here the contract is replaced by a
 * recorder that counts the calls: none for a job that builds nothing, one for
 * a build - which shows the recorder is the one the adoption asks.
 */
final class AnAdoptionOfAJobThatBuildsNothingAsksTheProviderNothingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the kind of each job the provider was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();

        $asked = &$this->asked;
        $this->app->instance(ReservationsFollowAnAdoption::class, new class($asked) implements ReservationsFollowAnAdoption
        {
            /** @param list<string> $asked */
            public function __construct(private array &$asked) {}

            public function lookFor(ProvisioningJob $job, string $providerReference): mixed
            {
                $this->asked[] = $job->kind->value;

                return null;
            }

            public function follow(ProvisioningJob $job, string $providerReference, mixed $looked): array
            {
                return [];
            }
        });
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function everyKindThatBuildsNothing(): iterable
    {
        foreach (ProvisioningJobKind::cases() as $kind) {
            if (! $kind->createsResource()) {
                yield $kind->value => [$kind];
            }
        }
    }

    #[Test]
    #[DataProvider('everyKindThatBuildsNothing')]
    public function the_provider_is_not_asked_about_a_job_that_builds_nothing(ProvisioningJobKind $kind): void
    {
        $this->adopt($this->jobInReview($kind))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.adoption_not_a_build');

        $this->assertSame([], $this->asked, 'The provider was asked about a job whose adoption is refused for its kind.');
    }

    #[Test]
    public function the_provider_is_asked_once_about_a_build(): void
    {
        $this->adopt($this->jobInReview(ProvisioningJobKind::CreateVps))->assertOk();

        $this->assertSame([ProvisioningJobKind::CreateVps->value], $this->asked);
    }

    private function adopt(ProvisioningJob $job): TestResponse
    {
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        return $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/adopt', [
            'provider_reference' => '4412',
            'evidence' => 'Looked at the provider.',
        ]);
    }

    private function jobInReview(ProvisioningJobKind $kind): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'kind' => $kind,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
        ]);
    }
}
