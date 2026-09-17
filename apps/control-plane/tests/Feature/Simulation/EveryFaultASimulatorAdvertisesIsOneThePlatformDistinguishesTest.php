<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two kinds of failure, and the difference is the whole point.
 *
 * ===========================================================================
 * WHY THIS IS ONE FILE FOR EVERY FAMILY
 * ===========================================================================
 *
 * Because the distinction the platform is built on is the same in all of them,
 * and it is the distinction a simulator is most likely to blur.
 *
 *   A **refusal** is an answer. The provider said no, nothing happened, and a
 *   caller may try again once whatever it objected to is fixed.
 *
 *   An **indeterminate** outcome is not an answer. The provider stopped
 *   talking, and whether it acted is unknown — so a retry may buy a second
 *   domain, build a second machine, charge a second time, or install over a
 *   site somebody has already written a post on. Under the Timeout Rule that
 *   is never resolved automatically: it goes to a person, or to
 *   reconciliation.
 *
 * Every provider exception in this repository carries `isIndeterminate()` for
 * exactly this reason, and every simulator advertises a marker for each shape.
 * A simulator whose timeout marker produced a plain refusal would let the
 * suite prove the platform handles a case the platform never sees — which is
 * worse than not testing it, because somebody would trust it.
 *
 * ===========================================================================
 * NO PROBABILITY, EVER
 * ===========================================================================
 *
 * Every fault below is selected from the request: a hostname, a username, a
 * record name, an archive id, an amount. Nothing is random, nothing depends on
 * a clock, and the same input produces the same failure on every run and in
 * every process. That is what makes a failure path something CI can assert
 * rather than something a flake investigation discovers.
 */
final class EveryFaultASimulatorAdvertisesIsOneThePlatformDistinguishesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_compute_simulator_tells_a_refusal_from_an_unknown_outcome(): void
    {
        $provider = new FakeComputeProvider;

        $refused = $this->catchCompute($provider, FakeComputeProvider::PROVIDER_FAILURE_MARKER);
        $this->assertFalse($refused->isIndeterminate());

        $unknown = $this->catchCompute($provider, FakeComputeProvider::TIMEOUT_MARKER);
        $this->assertTrue($unknown->isIndeterminate());

        /*
         * And the third shape, which is neither: the cluster accepted the job
         * and failed it minutes later. Nothing throws, and the platform finds
         * out by polling — which is the branch a fake that reported every
         * task complete on the first poll would leave uncovered.
         */
        $operation = $provider->createVirtualMachine($this->createRequest(9101, 'rehearsal-task-fail'));

        $settled = $provider->getTask('pve-01', $operation->taskId);

        $this->assertTrue($settled->isFinished());
        $this->assertFalse($settled->isSuccessful());
    }

    #[Test]
    public function the_bmc_simulator_tells_a_refusal_from_an_unknown_outcome(): void
    {
        $refused = $this->catchBmc(FakeDedicatedProvider::PROVIDER_FAILURE_MARKER);
        $this->assertFalse($refused->isIndeterminate());

        $unknown = $this->catchBmc(FakeDedicatedProvider::TIMEOUT_MARKER);
        $this->assertTrue($unknown->isIndeterminate());

        // The third shape here is a machine that answers and is dying, which
        // is the failure the dedicated module has to behave correctly about.
        $health = (new FakeDedicatedProvider)->hardwareHealth(
            $this->bmcEndpoint(FakeDedicatedProvider::addressWith('192.0.2.150', FakeDedicatedProvider::UNHEALTHY_MARKER)),
        );

        $this->assertSame(ComponentHealth::Critical, $health->overall);
    }

    #[Test]
    public function the_hosting_simulator_tells_a_refusal_from_an_unknown_outcome(): void
    {
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);
        $provider = new FakeHostingProvider;

        $refused = $this->catchHosting($provider, $node, 'web-'.FakeHostingProvider::PROVIDER_FAILURE_MARKER);
        $this->assertFalse($refused->isIndeterminate());

        $unknown = $this->catchHosting($provider, $node, 'web-'.FakeHostingProvider::TIMEOUT_MARKER);
        $this->assertTrue($unknown->isIndeterminate());

        /*
         * And the panel that answers with no numbers at all, which is what a
         * node mid-restart does. Every measurement is null and the caller must
         * leave the stored record alone rather than write zeroes over a
         * customer's real figures.
         */
        $provider->createAccount($node, $this->accountRequest('web-'.FakeHostingProvider::NO_USAGE_MARKER));

        $usage = $provider->accountUsage($node, 'web-'.FakeHostingProvider::NO_USAGE_MARKER);

        $this->assertNull($usage->diskUsedMib);
        $this->assertNull($usage->bandwidthUsedMib);
    }

    #[Test]
    public function the_backup_simulator_tells_a_refusal_from_an_unknown_outcome(): void
    {
        $provider = new FakeBackupProvider;

        $refused = $this->catchBackup($provider, FakeBackupProvider::REFUSAL_MARKER);
        $this->assertFalse($refused->isIndeterminate());

        $unknown = $this->catchBackup($provider, FakeBackupProvider::TIMEOUT_MARKER);
        $this->assertTrue($unknown->isIndeterminate());

        // A verification that ran and failed, which is a different answer
        // again: the archive exists and cannot be trusted.
        $archive = $this->storedArchive($provider);

        $failing = $provider->startVerification('pve-01', 'ref-datastore', $archive.'-'.FakeBackupProvider::FAILING_MARKER);

        $provider->taskState('pve-01', $failing->taskId);

        $this->assertFalse($provider->taskState('pve-01', $failing->taskId)->successful);
    }

    #[Test]
    public function a_verification_writes_its_verdict_onto_the_archive(): void
    {
        /*
         * Three answers and not two, exactly as the real datastore reports
         * them in a storage listing: never verified is not the same as
         * verified and failed, and a platform that collapsed them would tell a
         * customer their backup is fine because nobody has checked.
         */
        $provider = new FakeBackupProvider;
        $archive = $this->storedArchive($provider);

        $this->assertNull($provider->listBackups('pve-01', 'ref-datastore', '9001')[0]->verified);

        $verification = $provider->startVerification('pve-01', 'ref-datastore', $archive);

        $provider->taskState('pve-01', $verification->taskId);
        $provider->taskState('pve-01', $verification->taskId);

        $this->assertTrue($provider->listBackups('pve-01', 'ref-datastore', '9001')[0]->verified);
    }

    #[Test]
    public function the_dns_simulator_tells_a_refusal_from_an_unknown_outcome_on_reads_as_well_as_writes(): void
    {
        $provider = new FakeDnsProvider;
        $zone = $provider->withZone('faults.example');

        try {
            $provider->publish($zone, DnsRecord::of(DnsRecordType::A, FakeDnsProvider::REFUSAL_MARKER.'.faults.example', '198.51.100.30'));
            $this->fail('The DNS simulator accepted a name it advertises as refused.');
        } catch (DnsProviderException $refused) {
            $this->assertFalse($refused->isIndeterminate());
        }

        try {
            $provider->publish($zone, DnsRecord::of(DnsRecordType::A, FakeDnsProvider::TIMEOUT_MARKER.'.faults.example', '198.51.100.31'));
            $this->fail('The DNS simulator answered a name it advertises as a timeout.');
        } catch (DnsProviderException $unknown) {
            $this->assertTrue($unknown->isIndeterminate());
        }

        /*
         * And on a read, which is the half that matters most: a provider that
         * has stopped answering has stopped answering questions too, and the
         * platform's most dangerous moment is when it asks "is this zone still
         * there" and believes a silence.
         */
        $this->expectException(DnsProviderException::class);

        $provider->findZone(FakeDnsProvider::TIMEOUT_MARKER.'.faults.example');
    }

    #[Test]
    public function the_reverse_dns_simulator_tells_a_refusal_from_an_unknown_outcome(): void
    {
        $provider = new FakeReverseDnsProvider;

        try {
            $provider->publish(IpAddressValue::fromString('198.51.100.40'), Hostname::fromString(FakeReverseDnsProvider::REFUSAL_MARKER.'.faults.example'));
            $this->fail('The reverse-DNS simulator accepted a hostname it advertises as refused.');
        } catch (ReverseDnsProviderException $refused) {
            $this->assertFalse($refused->isIndeterminate());
        }

        try {
            $provider->publish(IpAddressValue::fromString('198.51.100.41'), Hostname::fromString(FakeReverseDnsProvider::TIMEOUT_MARKER.'.faults.example'));
            $this->fail('The reverse-DNS simulator answered a hostname it advertises as a timeout.');
        } catch (ReverseDnsProviderException $unknown) {
            $this->assertTrue($unknown->isIndeterminate());
        }

        $this->assertSame(0, $provider->publishedCount());
    }

    #[Test]
    public function the_registrar_simulator_tells_every_commercial_failure_apart(): void
    {
        $provider = new FakeDomainRegistrarProvider;

        // A name somebody else has: final, refundable, and not a fault.
        try {
            $provider->register($this->registration('already'.FakeDomainRegistrarProvider::TAKEN_MARKER.'.com'));
            $this->fail('The registrar simulator registered a name it advertises as taken.');
        } catch (DomainRegistrarException $taken) {
            $this->assertFalse($taken->isIndeterminate());
        }

        // A registry that went quiet during a purchase: the name IS registered
        // and the caller is told nothing.
        try {
            $provider->register($this->registration('quiet'.FakeDomainRegistrarProvider::TIMEOUT_MARKER.'.com'));
            $this->fail('The registrar simulator answered a registration it advertises as a timeout.');
        } catch (DomainRegistrarException $unknown) {
            $this->assertTrue($unknown->isIndeterminate());
        }

        /*
         * And the one that is a distinct marker on purpose: a registrar that
         * does not answer an *availability* check. One marker for both moments
         * would make the interesting case — a name that searches cleanly and
         * then times out with the customer's money in flight — impossible to
         * rehearse.
         */
        try {
            $provider->checkAvailability(['silent'.FakeDomainRegistrarProvider::UNREACHABLE_MARKER.'.com']);
            $this->fail('The registrar simulator answered an availability check it advertises as unreachable.');
        } catch (DomainRegistrarException $unreachable) {
            $this->assertTrue($unreachable->isIndeterminate());
        }

        // A transfer that never finishes, which is the ordinary case at every
        // registry: the losing registrar lets the five days run out.
        $provider->startTransfer('slow'.FakeDomainRegistrarProvider::SLOW_TRANSFER_MARKER.'.com', 'AUTHCODE123456');

        $this->assertSame('pending', $provider->transferStatus('slow'.FakeDomainRegistrarProvider::SLOW_TRANSFER_MARKER.'.com')->state);
        $this->assertSame('pending', $provider->transferStatus('slow'.FakeDomainRegistrarProvider::SLOW_TRANSFER_MARKER.'.com')->state);
    }

    #[Test]
    public function the_payment_simulator_declines_by_name_and_refuses_a_currency_it_does_not_take(): void
    {
        $provider = new FakePaymentProvider;

        foreach (['card_declined', 'insufficient_funds', 'expired_card', 'processing_error'] as $code) {
            $result = $provider->createPaymentIntent(new PaymentIntentRequest(
                amount: FakePaymentProvider::declineAmount(Money::ofMinor(20_000, 'KWD'), $code),
                idempotencyKey: 'faults-'.$code,
                confirm: true,
            ));

            $this->assertSame(RemotePaymentStatus::Failed, $result->status);
            $this->assertSame($code, $result->failureCode);
        }

        // A currency the gateway does not take is a refusal before anything is
        // attempted, not a decline of an attempt.
        $this->expectException(UnsupportedCurrencyException::class);

        $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(1_000, 'JPY'),
            idempotencyKey: 'faults-currency',
        ));
    }

    #[Test]
    public function every_fault_is_the_same_fault_on_every_run(): void
    {
        /*
         * No probability anywhere. The same request produces the same failure
         * twice in a row and in a second instance that never saw the first —
         * which is what lets CI assert a failure path instead of investigating
         * a flake.
         */
        $first = $this->catchCompute(new FakeComputeProvider, FakeComputeProvider::PROVIDER_FAILURE_MARKER);
        $second = $this->catchCompute(new FakeComputeProvider, FakeComputeProvider::PROVIDER_FAILURE_MARKER);

        $this->assertSame($first->getMessage(), $second->getMessage());
        $this->assertSame($first->context(), $second->context());
    }

    private function catchCompute(FakeComputeProvider $provider, string $marker): ComputeProviderException
    {
        try {
            $provider->createVirtualMachine($this->createRequest(9100, FakeComputeProvider::failingHostname('faults', $marker)));
        } catch (ComputeProviderException $caught) {
            return $caught;
        }

        $this->fail(sprintf('The compute simulator accepted a hostname carrying %s.', $marker));
    }

    private function catchBmc(string $marker): DedicatedProviderException
    {
        try {
            (new FakeDedicatedProvider)->powerOn(
                $this->bmcEndpoint(FakeDedicatedProvider::addressWith('192.0.2.151', $marker)),
            );
        } catch (DedicatedProviderException $caught) {
            return $caught;
        }

        $this->fail(sprintf('The BMC simulator accepted an address carrying %s.', $marker));
    }

    private function catchHosting(FakeHostingProvider $provider, HostingNode $node, string $username): HostingProviderException
    {
        try {
            $provider->createAccount($node, $this->accountRequest($username));
        } catch (HostingProviderException $caught) {
            return $caught;
        }

        $this->fail(sprintf('The hosting simulator accepted the username %s.', $username));
    }

    private function catchBackup(FakeBackupProvider $provider, string $marker): BackupProviderException
    {
        try {
            $provider->startBackup(new BackupRequest('pve-01', '9001', 'ref-datastore', notes: $marker));
        } catch (BackupProviderException $caught) {
            return $caught;
        }

        $this->fail(sprintf('The backup simulator accepted notes carrying %s.', $marker));
    }

    /**
     * One settled archive, so a verification has something to be about.
     */
    private function storedArchive(FakeBackupProvider $provider): string
    {
        $operation = $provider->startBackup(new BackupRequest('pve-01', '9001', 'ref-datastore'));

        $provider->taskState('pve-01', $operation->taskId);

        $settled = $provider->taskState('pve-01', $operation->taskId);

        $this->assertNotNull($settled->archiveId);

        return $settled->archiveId;
    }

    private function createRequest(int $vmId, string $hostname): CreateVmRequest
    {
        return new CreateVmRequest(
            nodeName: 'pve-01',
            vmId: $vmId,
            hostname: $hostname,
            vcpu: 2,
            memoryMib: 2048,
            diskGib: 20,
            storageName: 'local-nvme',
        );
    }

    private function bmcEndpoint(string $address): BmcEndpoint
    {
        return BmcEndpoint::query()->create([
            'dedicated_server_id' => DedicatedServer::factory()->create()->getKey(),
            'protocol' => BmcProtocol::Redfish,
            'address' => $address,
            'port' => 443,
            'verify_tls' => true,
        ]);
    }

    private function accountRequest(string $username): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: $username,
            primaryDomain: 'faults.example',
            password: 'a-rehearsal-password',
            packageName: 'ref_starter',
            contactEmail: 'rehearsal@faults.example',
        );
    }

    private function registration(string $name): RegistrationRequest
    {
        return new RegistrationRequest(
            name: $name,
            termYears: 1,
            contacts: ['registrant' => new ContactDetails(
                name: 'A Rehearsal',
                email: 'rehearsal@faults.example',
                phone: '+96500000000',
                addressLineOne: 'A reference address',
                city: 'Reference City',
                country: 'ZZ',
            )],
        );
    }
}
