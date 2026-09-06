<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Handlers\FakeProvisioningHandler;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use Lynomia\Modules\Provisioning\Infrastructure\Releasers\FakeResourceReservationReleaser;
use Tests\TestCase;

/**
 * Shared wiring for the provisioning engine's tests.
 *
 * The two bindings below are the ones the application makes at boot, and both
 * are deliberately absent from the module itself: the engine is
 * provider-agnostic, so it ships no handlers, and it does not depend on IPAM,
 * so it ships no releaser. A test has to supply them for the same reason
 * production does.
 *
 * The queue is faked throughout. A scheduled retry is a message the engine
 * pushes, so faking the queue is both how a test observes that decision and
 * what stops the sync driver executing the retry inside the attempt that
 * scheduled it.
 */
abstract class ProvisioningTestCase extends TestCase
{
    protected ProvisioningHandlerRegistry $handlers;

    protected FakeResourceReservationReleaser $releaser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->handlers = new ProvisioningHandlerRegistry($this->app);
        $this->releaser = new FakeResourceReservationReleaser;

        $this->app->instance(HandlerRegistry::class, $this->handlers);
        $this->app->instance(ResourceReservationReleaser::class, $this->releaser);

        foreach (ProvisioningJobKind::cases() as $kind) {
            $this->handlers->register(new FakeProvisioningHandler($kind));
        }
    }

    /**
     * Run one attempt exactly as a queue worker would: resolve the job's
     * dependencies from the container and hand it the job.
     *
     * Deliberately not dispatchSync(). With a faked queue that routes the
     * command to the "sync" connection, which the fake records instead of
     * executing — the attempt would never happen and every assertion below
     * would be about a job nobody ran.
     */
    protected function runWorker(ProvisioningJob|string $job): void
    {
        $id = $job instanceof ProvisioningJob ? (string) $job->getKey() : $job;

        $this->app->call([new RunProvisioningJob($id), 'handle']);
    }
}
