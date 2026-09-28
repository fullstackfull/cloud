<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adoption attaches a resource a build made and never answered for; a job of
 * any other kind is refused it (B-1, the verification of round eight A).
 *
 * A resize in review was adopted under any reference, settled as succeeded
 * with nothing read from its machine and the machine row never written, and
 * a downgrade was then credited from that row. Every kind is asked here: the
 * three that build a resource (ProvisioningJobKind::createsResource()) are
 * not refused for their kind; every other kind is, before the provider is
 * asked anything, and the job stays in review.
 */
final class OnlyAJobThatBuildsAResourceCanAdoptOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function everyKind(): iterable
    {
        foreach (ProvisioningJobKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[Test]
    #[DataProvider('everyKind')]
    public function a_job_is_adoptable_only_if_it_builds_a_resource(ProvisioningJobKind $kind): void
    {
        $job = ProvisioningJob::factory()->create([
            'kind' => $kind,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
        ]);

        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $response = $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/adopt', [
            'provider_reference' => 'adopted-'.$kind->value,
            'evidence' => 'Looked at the provider.',
        ]);

        if ($kind->createsResource()) {
            $this->assertNotSame('provisioning.adoption_not_a_build', $response->json('error.code'), 'A build was refused adoption for its kind.');

            return;
        }

        $response->assertStatus(409)->assertJsonPath('error.code', 'provisioning.adoption_not_a_build');
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertSame(0, AuditEntry::query()->count());
    }
}
