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
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
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
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mutate, then read. Twice per family, and the second time it failed.
 *
 * ===========================================================================
 * WHY THIS IS MANDATORY AND NOT THOROUGH
 * ===========================================================================
 *
 * The cheapest possible simulator returns success and stores nothing. Every
 * test that calls it passes, the suite grows around it, and what has been
 * proven is that the platform can make a call — not that it can make a change.
 * The whole value of a stateful simulator is that a read afterwards disagrees
 * with a fake that did nothing, so each family below performs a mutation and
 * then asks the provider, through its own contract, whether it happened.
 *
 * ===========================================================================
 * AND THE OTHER HALF, WHICH IS WHERE THE BUGS ARE
 * ===========================================================================
 *
 * A failed mutation must leave nothing behind. That is harder than it sounds
 * and it is where a real adapter's partial writes live, so each family is
 * asked to fail and then asked again what it holds. The answer has to be
 * "nothing changed" — except where the platform's own contract says the
 * outcome is unknown, which is a different and honest answer: the WordPress
 * install and the registrar registration both record the work and then refuse
 * to answer, because a toolkit and a registry that go quiet have usually
 * finished. Those two cases are asserted as what they are rather than
 * flattened into "nothing happened", because a platform that retried them
 * would install over a customer's site and buy a second year of a domain.
 *
 * The hypervisor joined them late (F-24). Its only unanswered create used to
 * throw before the machine existed and its only unanswered destroy before the
 * machine was gone, so its own state settled every unknown outcome in the
 * direction that cost nothing — and a platform that retried an unanswered
 * create into a second machine passed every test here. Both shapes are now
 * reachable, and asserted below as what they are.
 *
 * A refusal is also where a simulator can be too agreeable. The panel used to
 * report an account opened, and a site installed, with a credential nobody
 * could log in with — the empty string, or the redactor's placeholder — and
 * the platform had sent both. It refuses them now, and records nothing.
 */
final class AControlledDriverThatSaysItDidSomethingDidItTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_compute_simulator_holds_the_machine_it_says_it_built(): void
    {
        $provider = new FakeComputeProvider;

        $operation = $provider->createVirtualMachine(new CreateVmRequest(
            nodeName: 'pve-01',
            vmId: 9001,
            hostname: 'rehearsal-one',
            vcpu: 2,
            memoryMib: 2048,
            diskGib: 20,
            storageName: 'local-nvme',
            templateReference: 'ref-image-9002',
        ));

        $machine = $provider->getVm('pve-01', '9001');

        $this->assertNotNull($machine, 'The simulator reported a machine created and holds none.');
        $this->assertSame('rehearsal-one', $machine->name);
        $this->assertSame(2, $machine->vcpu);
        $this->assertSame('9001', $operation->providerId);

        // The image is observable, so a test can assert the disk came from the
        // template that was asked for rather than that a call was made.
        $this->assertSame('ref-image-9002', $machine->raw['installed_template'] ?? null);

        $this->assertCount(1, $provider->listVms('pve-01'));

        $provider->destroyVm('pve-01', '9001');

        $this->assertNull($provider->getVm('pve-01', '9001'), 'A destroyed machine is still there.');
        $this->assertSame([], $provider->listVms('pve-01'));
    }

    #[Test]
    public function a_refused_build_leaves_no_machine_behind(): void
    {
        $provider = new FakeComputeProvider;

        try {
            $provider->createVirtualMachine(new CreateVmRequest(
                nodeName: 'pve-01',
                vmId: 9002,
                hostname: FakeComputeProvider::failingHostname('rehearsal-two'),
                vcpu: 2,
                memoryMib: 2048,
                diskGib: 20,
                storageName: 'local-nvme',
            ));

            $this->fail('The simulator accepted a hostname it is supposed to refuse.');
        } catch (ComputeProviderException) {
            // The refusal is the point; what matters is what it left.
        }

        $this->assertNull($provider->getVm('pve-01', '9002'));
        $this->assertSame([], $provider->listVms('pve-01'));
    }

    #[Test]
    public function an_unanswered_build_may_have_built_and_an_unanswered_destroy_may_have_destroyed(): void
    {
        $provider = new FakeComputeProvider;

        $built = $this->vmRequest(9003, FakeComputeProvider::failingHostname('rehearsal-three', FakeComputeProvider::BUILT_UNANSWERED_MARKER));

        try {
            $provider->createVirtualMachine($built);
            $this->fail('The simulator answered a create it is supposed to go quiet on after building.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate(), 'An unanswered create was reported as a refusal.');
        }

        $this->assertNotNull(
            $provider->getVm('pve-01', '9003'),
            'A hypervisor that went quiet built nothing, so a retry into a second machine is still unrepresentable.',
        );

        $removed = $this->vmRequest(9004, FakeComputeProvider::failingHostname('rehearsal-four', FakeComputeProvider::DESTROYED_UNANSWERED_MARKER));
        $provider->createVirtualMachine($removed);

        try {
            $provider->destroyVm('pve-01', '9004');
            $this->fail('The simulator answered a destroy it is supposed to go quiet on after destroying.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate(), 'An unanswered destroy was reported as a refusal.');
        }

        $this->assertNull(
            $provider->getVm('pve-01', '9004'),
            'A hypervisor that went quiet on a destroy kept the machine, which is the only answer it used to give.',
        );
    }

    #[Test]
    public function the_bmc_simulator_remembers_what_it_powered(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->bmcEndpoint('192.0.2.140');

        // A freshly racked machine is off, which is what a real one is.
        $this->assertSame(PowerState::Off, $provider->powerState($endpoint));

        $provider->powerOn($endpoint);

        $this->assertSame(PowerState::On, $provider->powerState($endpoint));

        $provider->gracefulShutdown($endpoint);

        $this->assertSame(PowerState::Off, $provider->powerState($endpoint));

        // The one-time override is armed, appears first in the boot order, and
        // is consumed by the next reset — which is what "one-time" means.
        $provider->setOneTimePxeBoot($endpoint);

        $this->assertSame(['Pxe', 'Hdd', 'Cd'], $provider->bootOrder($endpoint));

        $provider->reset($endpoint);

        $this->assertSame(['Hdd', 'Pxe', 'Cd'], $provider->bootOrder($endpoint));
        $this->assertFalse($provider->isPxeArmed($endpoint));
    }

    #[Test]
    public function a_refused_power_operation_changes_nothing(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->bmcEndpoint(FakeDedicatedProvider::addressWith('192.0.2.141', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER));

        $this->expectException(DedicatedProviderException::class);

        try {
            $provider->powerOn($endpoint);
        } finally {
            // Read through a provider that does not refuse, because the
            // refusing one refuses reads too — which is correct, and is why
            // the observation has to come from somewhere else.
            $this->assertSame(
                PowerState::Off,
                (new FakeDedicatedProvider)->powerState($this->bmcEndpoint('192.0.2.141')),
            );
        }
    }

    #[Test]
    public function the_hosting_simulator_holds_the_account_it_says_it_created(): void
    {
        $provider = new FakeHostingProvider;
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $provider->createAccount($node, $this->accountRequest('rehearse1'));

        $accounts = $provider->listAccounts($node);

        $this->assertCount(1, $accounts);
        $this->assertSame('rehearse1', $accounts[0]->username);
        $this->assertFalse($accounts[0]->suspended);

        $provider->suspendAccount($node, 'rehearse1', 'a rehearsal');

        $this->assertTrue($provider->listAccounts($node)[0]->suspended);

        $provider->unsuspendAccount($node, 'rehearse1');

        $this->assertFalse($provider->listAccounts($node)[0]->suspended);

        $provider->terminateAccount($node, 'rehearse1');

        $this->assertSame([], $provider->listAccounts($node));
    }

    #[Test]
    public function a_refused_account_is_not_created_and_a_duplicate_is_refused(): void
    {
        $provider = new FakeHostingProvider;
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        try {
            $provider->createAccount($node, $this->accountRequest('web-provider-fail'));
            $this->fail('The simulator accepted a username it is supposed to refuse.');
        } catch (HostingProviderException) {
            // As above.
        }

        $this->assertSame([], $provider->listAccounts($node));

        /*
         * And the collision the platform depends on: a retried create that
         * silently succeeded twice would be two accounts, or one account
         * whose password the customer was never told.
         */
        $provider->createAccount($node, $this->accountRequest('rehearse2'));

        $this->expectException(HostingProviderException::class);

        $provider->createAccount($node, $this->accountRequest('rehearse2'));
    }

    #[Test]
    public function a_credential_nobody_can_log_in_with_opens_no_account_and_installs_no_site(): void
    {
        $provider = new FakeHostingProvider;
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        try {
            $provider->createAccount($node, $this->accountRequest('rehearse5', password: SecretRedactor::PLACEHOLDER));
            $this->fail("The simulator opened an account with the redactor's placeholder as its password.");
        } catch (HostingProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $this->assertSame([], $provider->listAccounts($node));

        $provider->createAccount($node, $this->accountRequest('rehearse5'));

        try {
            $provider->installWordPress($node, $this->installRequest('rehearse5', 'site.example', adminPassword: ''));
            $this->fail('The simulator installed a site whose administrator password is empty.');
        } catch (HostingProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $this->assertFalse($provider->wordPressInstallation($node, 'rehearse5', 'site.example')->exists);
    }

    #[Test]
    public function the_wordpress_simulator_holds_the_installation_it_says_it_made(): void
    {
        $provider = new FakeHostingProvider;
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $provider->createAccount($node, $this->accountRequest('rehearse3'));

        $installed = $provider->installWordPress($node, $this->installRequest('rehearse3', 'site.example'));

        $this->assertTrue($installed->exists);

        $found = $provider->wordPressInstallation($node, 'rehearse3', 'site.example');

        $this->assertTrue($found->exists);
        $this->assertSame('https://site.example', $found->siteUrl);

        // A domain nothing was installed on answers, and the answer is "there
        // is nothing here" — which is how reconciliation learns an install the
        // platform believes in did not happen.
        $this->assertFalse($provider->wordPressInstallation($node, 'rehearse3', 'absent.example')->exists);
    }

    #[Test]
    public function a_refused_install_writes_nothing_and_an_unanswered_one_admits_it_may_have(): void
    {
        $provider = new FakeHostingProvider;
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $provider->createAccount($node, $this->accountRequest('rehearse4'));

        try {
            $provider->installWordPress($node, $this->installRequest('rehearse4', 'wp-refused.example'));
            $this->fail('The simulator accepted a domain it is supposed to refuse.');
        } catch (HostingProviderException $refused) {
            $this->assertFalse($refused->indeterminate ?? false);
        }

        $this->assertFalse($provider->wordPressInstallation($node, 'rehearse4', 'wp-refused.example')->exists);

        /*
         * The other shape, and the important one. An installer that stops
         * answering has usually written a database and a wp-config, so the
         * simulator records the installation and then refuses to answer. A
         * platform that treated this as "nothing happened" and retried would
         * install over a site the customer may already have written a post on.
         */
        try {
            $provider->installWordPress($node, $this->installRequest('rehearse4', 'wp-timeout.example'));
            $this->fail('The simulator answered an install it is supposed to go quiet on.');
        } catch (HostingProviderException) {
            // As above.
        }

        $this->assertTrue(
            $provider->wordPressInstallation($node, 'rehearse4', 'wp-timeout.example')->exists,
            'An installer that went quiet left nothing behind, which is the one thing it must not model.',
        );
    }

    #[Test]
    public function the_backup_simulator_holds_the_archive_its_task_produced(): void
    {
        $provider = new FakeBackupProvider;

        $operation = $provider->startBackup(new BackupRequest('pve-01', '9001', 'ref-datastore'));

        // Running first, because a reconciler that only ever polls once is
        // visibly wrong.
        $this->assertFalse($provider->taskState('pve-01', $operation->taskId)->finished);

        $settled = $provider->taskState('pve-01', $operation->taskId);

        $this->assertTrue($settled->finished);
        $this->assertTrue($settled->successful);

        $archives = $provider->listBackups('pve-01', 'ref-datastore', '9001');

        $this->assertCount(1, $archives);
        $this->assertSame($settled->archiveId, $archives[0]->archiveId);

        // Created is not verified, and the archive says so until something
        // verifies it.
        $this->assertNull($archives[0]->verified);

        $provider->deleteBackup('pve-01', 'ref-datastore', $archives[0]->archiveId);

        $this->assertSame([], $provider->listBackups('pve-01', 'ref-datastore', '9001'));
    }

    #[Test]
    public function a_failed_backup_leaves_no_archive(): void
    {
        $provider = new FakeBackupProvider;

        $operation = $provider->startBackup(new BackupRequest(
            'pve-01',
            '9002',
            'ref-datastore',
            notes: FakeBackupProvider::FAILING_MARKER,
        ));

        $provider->taskState('pve-01', $operation->taskId);
        $settled = $provider->taskState('pve-01', $operation->taskId);

        $this->assertTrue($settled->finished);
        $this->assertFalse($settled->successful);
        $this->assertSame([], $provider->listBackups('pve-01', 'ref-datastore', '9002'));

        // And a refusal before the task exists at all.
        $this->expectException(BackupProviderException::class);

        $provider->startBackup(new BackupRequest('pve-01', '9003', 'ref-datastore', notes: FakeBackupProvider::REFUSAL_MARKER));
    }

    #[Test]
    public function the_dns_simulator_holds_the_record_it_says_it_published(): void
    {
        $provider = new FakeDnsProvider;
        $zone = $provider->withZone('rehearsal.example');

        $record = $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'www.rehearsal.example', '198.51.100.10'));

        $this->assertNotNull($record->id());
        $this->assertCount(1, $provider->records($zone));

        // A repeat keeps the identifier: one record, one id, whether written
        // once or three times.
        $again = $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'www.rehearsal.example', '198.51.100.10'));

        $this->assertSame($record->id(), $again->id());
        $this->assertCount(1, $provider->records($zone));

        // A second address at the name is a second record, not the first one
        // overwritten: the simulator holds what a real zone holds, which is
        // what lets it catch an adapter that collapses the two (F-11).
        $sibling = $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'www.rehearsal.example', '198.51.100.11'));

        $this->assertNotSame($record->id(), $sibling->id());
        $this->assertCount(2, $provider->records($zone));

        // And removing one leaves the other answering.
        $provider->delete($zone, $again);

        $left = $provider->records($zone);
        $this->assertCount(1, $left);
        $this->assertSame('198.51.100.11', $left[0]->content());
    }

    #[Test]
    public function a_refused_record_is_not_published(): void
    {
        $provider = new FakeDnsProvider;
        $zone = $provider->withZone('rehearsal.example');

        try {
            $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'dns-refused.rehearsal.example', '198.51.100.12'));
            $this->fail('The simulator accepted a name it is supposed to refuse.');
        } catch (DnsProviderException) {
            // As above.
        }

        $this->assertSame([], $provider->records($zone));
    }

    #[Test]
    public function the_reverse_dns_simulator_holds_one_name_per_address(): void
    {
        $provider = new FakeReverseDnsProvider;
        $address = IpAddressValue::fromString('198.51.100.20');

        $provider->publish($address, Hostname::fromString('one.rehearsal.example'));

        $this->assertSame('one.rehearsal.example', $provider->publishedFor('198.51.100.20'));
        $this->assertSame(1, $provider->publishedCount());

        // A repeat replaces rather than appends, which is the idempotence the
        // interface promises.
        $provider->publish($address, Hostname::fromString('two.rehearsal.example'));

        $this->assertSame('two.rehearsal.example', $provider->publishedFor('198.51.100.20'));
        $this->assertSame(1, $provider->publishedCount());
    }

    #[Test]
    public function a_refused_ptr_is_not_published(): void
    {
        $provider = new FakeReverseDnsProvider;

        try {
            $provider->publish(
                IpAddressValue::fromString('198.51.100.21'),
                Hostname::fromString('ptr-refused.rehearsal.example'),
            );
            $this->fail('The simulator accepted a hostname it is supposed to refuse.');
        } catch (ReverseDnsProviderException) {
            // As above.
        }

        $this->assertNull($provider->publishedFor('198.51.100.21'));
        $this->assertSame(0, $provider->publishedCount());
    }

    #[Test]
    public function the_registrar_simulator_holds_the_name_it_says_it_registered(): void
    {
        $provider = new FakeDomainRegistrarProvider;

        $registered = $provider->register($this->registration('rehearsal-one.com'));

        $this->assertSame('rehearsal-one.com', $registered->name);
        $this->assertContains('rehearsal-one.com', $provider->heldNames());
        $this->assertTrue($provider->inspect('rehearsal-one.com')->transferLocked);

        /*
         * A renewal moves the expiry from the expiry and not from today, so a
         * customer renewing early does not lose the days they paid for twice.
         */
        $expiry = $registered->expiresAt;
        $renewed = $provider->renew('rehearsal-one.com', 1);

        $this->assertSame($expiry->addYear()->toIso8601String(), $renewed->expiresAt->toIso8601String());

        /*
         * And the registration is idempotent on the name: a redelivered job
         * gets the registration this account already has rather than a second
         * term.
         *
         * Asserted *after* the renewal on purpose. The first version of this
         * compared the expiry of two registrations made in the same second,
         * and a deliberate breakage that removed the idempotency branch
         * altogether left it passing — both calls computed "a year from now"
         * and agreed. Renewing first gives the holding an expiry that a fresh
         * registration could not produce, so a second term is now visible as
         * the year it would silently cost the customer.
         */
        $this->assertSame(
            $renewed->expiresAt->toIso8601String(),
            $provider->register($this->registration('rehearsal-one.com'))->expiresAt->toIso8601String(),
            'A redelivered registration overwrote a renewed holding, which is a year the customer paid for and lost.',
        );

        /*
         * And the twin, because an idempotency gate that passed by refusing
         * every second registration would be worse than none: two different
         * names are two registrations.
         */
        $provider->register($this->registration('rehearsal-two.com'));

        $held = $provider->heldNames();

        sort($held);

        $this->assertSame(['rehearsal-one.com', 'rehearsal-two.com'], $held);
    }

    #[Test]
    public function a_refused_registration_holds_nothing(): void
    {
        $provider = new FakeDomainRegistrarProvider;

        try {
            $provider->register($this->registration('rehearsal-refused.com'));
            $this->fail('The simulator accepted a name it is supposed to refuse.');
        } catch (DomainRegistrarException) {
            // As above.
        }

        $this->assertSame([], $provider->heldNames());

        /*
         * The registrar's indeterminate case, which is the opposite and is
         * modelled honestly: the name IS registered and the caller is told
         * nothing. A platform that retried would buy a second term; one that
         * called it failed would refund a domain the customer owns.
         */
        try {
            $provider->register($this->registration('rehearsal-timeout.com'));
            $this->fail('The simulator answered a registration it is supposed to go quiet on.');
        } catch (DomainRegistrarException) {
            // As above.
        }

        $this->assertContains('rehearsal-timeout.com', $provider->heldNames());
    }

    #[Test]
    public function the_payment_simulator_reports_the_same_outcome_to_a_process_that_never_saw_the_request(): void
    {
        $provider = new FakePaymentProvider;
        $amount = Money::ofMinor(15_000, 'KWD');

        $intent = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: $amount,
            idempotencyKey: 'rehearsal-1',
            confirm: true,
        ));

        $this->assertSame(RemotePaymentStatus::Succeeded, $intent->status);

        // A second provider instance, standing in for the queue worker that
        // never saw the request: the outcome travels in the reference.
        $retrieved = (new FakePaymentProvider)->retrievePayment($intent->reference);

        $this->assertSame(RemotePaymentStatus::Succeeded, $retrieved->status);
        $this->assertSame($amount->minorUnits(), $retrieved->amount->minorUnits());
        $this->assertNotNull($retrieved->chargeReference);
    }

    #[Test]
    public function a_declined_payment_has_no_charge_to_refund(): void
    {
        $provider = new FakePaymentProvider;

        $declined = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: FakePaymentProvider::declineAmount(Money::ofMinor(15_000, 'KWD')),
            idempotencyKey: 'rehearsal-2',
            confirm: true,
        ));

        $this->assertSame(RemotePaymentStatus::Failed, $declined->status);
        $this->assertSame('card_declined', $declined->failureCode);
        $this->assertNull((new FakePaymentProvider)->retrievePayment($declined->reference)->chargeReference);
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

    private function vmRequest(int $vmId, string $hostname): CreateVmRequest
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

    private function accountRequest(string $username, string $password = 'a-rehearsal-password'): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example',
            password: $password,
            packageName: 'ref_starter',
            contactEmail: 'rehearsal@rehearsal.example',
        );
    }

    private function installRequest(
        string $username,
        string $domain,
        string $adminPassword = 'a-rehearsal-password',
    ): WordPressInstallRequest {
        return new WordPressInstallRequest(
            username: $username,
            domain: $domain,
            adminUsername: 'rehearsal',
            adminPassword: $adminPassword,
            adminEmail: 'rehearsal@rehearsal.example',
            siteTitle: 'A rehearsal',
        );
    }

    private function registration(string $name): RegistrationRequest
    {
        return new RegistrationRequest(
            name: $name,
            termYears: 1,
            contacts: ['registrant' => new ContactDetails(
                name: 'A Rehearsal',
                email: 'rehearsal@rehearsal.example',
                phone: '+96500000000',
                addressLineOne: 'A reference address',
                city: 'Reference City',
                country: 'ZZ',
            )],
        );
    }
}
