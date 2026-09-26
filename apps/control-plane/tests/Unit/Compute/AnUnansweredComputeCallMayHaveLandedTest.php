<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Closure;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The controlled hypervisor can lose the answer to work it really did (F-24).
 *
 * ===========================================================================
 * WHAT WAS WRONG
 * ===========================================================================
 *
 * Every indeterminate outcome this simulator could produce was resolved in the
 * convenient direction by its own state. A create carrying the timeout marker
 * threw before the machine was registered, so "the call did not answer" always
 * meant "nothing was built" and a retry always built cleanly; a destroy
 * carrying the undestroyable marker threw before the machine was removed, so
 * "the destroy did not answer" always meant "it is still there". A platform
 * that retried an unanswered create into a second machine for the same order
 * could not be caught by any test that ran against this simulator, because the
 * first machine never existed.
 *
 * The registrar simulator had the honest shape all along: a registration that
 * times out writes the holding and then refuses to answer. These markers copy
 * that shape, in both directions — a create that built and a destroy that
 * removed, each followed by an indeterminate failure.
 *
 * ===========================================================================
 * WHY EVERY CASE GOES THROUGH ONE HELPER
 * ===========================================================================
 *
 * A marker that does nothing is an ordinary hostname, and with an ordinary
 * hostname the create simply succeeds — after which "the machine is there" is
 * true and every assertion about it passes over a premise that never happened.
 * So each call that is supposed to lose its answer goes through
 * {@see self::unanswered()}, which fails the test outright unless an
 * indeterminate exception was actually thrown. Remove the behaviour behind
 * either marker and every case here is red at that helper, not at an
 * assertion downstream of it.
 *
 * Nothing here moves a REAL_* status. A simulator that models the dangerous
 * case is still a simulator; what this makes possible is a test of the
 * platform against that case, not evidence about any real cluster.
 */
final class AnUnansweredComputeCallMayHaveLandedTest extends TestCase
{
    private FakeComputeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeComputeProvider;
    }

    #[Test]
    public function a_create_whose_answer_is_lost_has_built_the_machine(): void
    {
        $hostname = FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::BUILT_UNANSWERED_MARKER);

        $this->unanswered(fn () => $this->provider->createVirtualMachine($this->request(101, $hostname)));

        $machine = $this->provider->getVm('pve-01', '101');

        $this->assertNotNull($machine, 'The create lost its answer and the simulator built nothing, which is the convenient case again.');
        $this->assertSame($hostname, $machine->name);
        $this->assertSame(4, $machine->vcpu);
        $this->assertSame(8192, $machine->memoryMib);
        $this->assertCount(1, $this->provider->listVms('pve-01'));
    }

    #[Test]
    public function a_create_retried_after_its_answer_was_lost_is_a_second_machine(): void
    {
        /*
         * The double-build, which is the mistake this marker exists to make
         * reachable. A platform that read "no answer" as "nothing happened"
         * and tried again under a fresh identity now has two machines running
         * for one order — and a test can count them.
         */
        $hostname = FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::BUILT_UNANSWERED_MARKER);

        $this->unanswered(fn () => $this->provider->createVirtualMachine($this->request(101, $hostname)));
        $this->unanswered(fn () => $this->provider->createVirtualMachine($this->request(102, $hostname)));

        $built = $this->provider->listVms('pve-01');

        $this->assertCount(2, $built, 'A retried unanswered create left one machine, so a double-build is still unrepresentable.');
        $this->assertSame(
            [$hostname, $hostname],
            array_map(static fn (object $machine): ?string => $machine->name, $built),
        );
    }

    #[Test]
    public function an_unanswered_create_can_mean_either_outcome(): void
    {
        /*
         * Both shapes, side by side, because a caller has to handle both and
         * cannot tell them apart from the exception: the timeout marker is the
         * create that never reached the cluster, this marker is the one that
         * did. Before, only the first existed.
         */
        $nothing = FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::TIMEOUT_MARKER);
        $landed = FakeComputeProvider::failingHostname('web-02', FakeComputeProvider::BUILT_UNANSWERED_MARKER);

        $first = $this->unanswered(fn () => $this->provider->createVirtualMachine($this->request(101, $nothing)));
        $second = $this->unanswered(fn () => $this->provider->createVirtualMachine($this->request(102, $landed)));

        $this->assertSame($first->errorCode(), $second->errorCode(), 'The two outcomes must be indistinguishable to the caller.');
        $this->assertNull($this->provider->getVm('pve-01', '101'));
        $this->assertNotNull($this->provider->getVm('pve-01', '102'));
    }

    #[Test]
    public function a_destroy_whose_answer_is_lost_has_removed_the_machine(): void
    {
        $hostname = FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::DESTROYED_UNANSWERED_MARKER);

        // The create itself answers: this marker acts on the destroy only.
        $this->provider->createVirtualMachine($this->request(101, $hostname));
        $this->assertNotNull($this->provider->getVm('pve-01', '101'));

        $this->unanswered(fn () => $this->provider->destroyVm('pve-01', '101'));

        $this->assertNull(
            $this->provider->getVm('pve-01', '101'),
            'The destroy lost its answer and the machine is still there, which is the convenient case again.',
        );
        $this->assertSame([], $this->provider->listVms('pve-01'));
    }

    #[Test]
    public function an_unanswered_destroy_can_mean_either_outcome(): void
    {
        /*
         * The other direction. An indeterminate destroy used to mean, always,
         * that the machine survived — so a platform that released the
         * machine's address on an unanswered destroy was wrong in every test
         * and a platform that kept billing for a machine that was gone was
         * right in every test. Now both are reachable.
         */
        $survives = FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::UNDESTROYABLE_MARKER);
        $gone = FakeComputeProvider::failingHostname('web-02', FakeComputeProvider::DESTROYED_UNANSWERED_MARKER);

        $this->provider->createVirtualMachine($this->request(101, $survives));
        $this->provider->createVirtualMachine($this->request(102, $gone));

        $first = $this->unanswered(fn () => $this->provider->destroyVm('pve-01', '101'));
        $second = $this->unanswered(fn () => $this->provider->destroyVm('pve-01', '102'));

        $this->assertSame($first->errorCode(), $second->errorCode(), 'The two outcomes must be indistinguishable to the caller.');
        $this->assertNotNull($this->provider->getVm('pve-01', '101'));
        $this->assertNull($this->provider->getVm('pve-01', '102'));
    }

    /**
     * Run a call that must lose its answer, and fail unless it did.
     *
     * @param  Closure(): mixed  $call
     */
    private function unanswered(Closure $call): ComputeProviderException
    {
        try {
            $call();
        } catch (ComputeProviderException $e) {
            $this->assertTrue(
                $e->isIndeterminate(),
                'The call failed, but as a refusal rather than as a lost answer: '.$e->getMessage(),
            );

            return $e;
        }

        $this->fail('The call answered. A marker that loses the answer produced none of the lost answer, so nothing after this line tests anything.');
    }

    private function request(int $vmId, string $hostname): CreateVmRequest
    {
        return new CreateVmRequest(
            nodeName: 'pve-01',
            vmId: $vmId,
            hostname: $hostname,
            vcpu: 4,
            memoryMib: 8192,
            diskGib: 80,
            storageName: 'local-nvme',
            cloudInit: new CloudInitConfig(sshKeys: ['ssh-ed25519 AAAA test@lynomia']),
        );
    }
}
