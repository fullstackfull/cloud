<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\Contracts\FileLevelBackupProvider;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Backups\Infrastructure\Providers\ProxmoxBackupProvider;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Domains\Domain\Contracts\DomainRegistrarProvider;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Domains\Infrastructure\Providers\SyRegistryProvider;
use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;
use Lynomia\Modules\Ipam\Infrastructure\Providers\CloudflareReverseDnsProvider;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract parity: a controlled driver can do everything the real one can.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO PREVENT
 * ===========================================================================
 *
 * A simulator that is missing an operation is worse than no simulator at all,
 * because the suite goes green over the gap. Every test that exercises the
 * nine operations a family has passes; the tenth is never called, and the
 * first time anybody discovers the platform cannot sequence it is against real
 * infrastructure, where the failure costs a customer's machine rather than a
 * red build.
 *
 * So the rule is stated in both directions and neither is optional:
 *
 *   - every interface the real adapter implements is implemented by the
 *     controlled driver, and every public operation the real adapter offers
 *     beyond those interfaces is offered by the controlled driver too, or
 *     appears in {@see self::REAL_ONLY} with the reason;
 *
 *   - every public operation the controlled driver offers is on one of those
 *     interfaces, or appears in {@see self::ARRANGEMENT} as a seam for
 *     arranging and observing a rehearsal.
 *
 * The second direction is the one that stops a simulator inventing provider
 * behaviour. A method on a fake that no interface names is a capability the
 * platform cannot call and a test can — which is how a suite comes to prove a
 * behaviour the product does not have.
 *
 * ===========================================================================
 * AND THE CONSUMER RULE
 * ===========================================================================
 *
 * A controlled driver in the catalogue with no simulator behind it is a
 * catalogue entry that lies, and a simulator with no catalogue entry is what
 * this repository had until Gap 6: eight stateful simulators reachable through
 * the per-family factories and invisible to the provider registry. Both
 * directions are checked below.
 */
final class EveryControlledDriverIsBackedByASimulatorTest extends TestCase
{
    /**
     * Every controlled driver, what simulates it, and what it stands in for.
     *
     * Written out by hand for the same reason the catalogue's adapter map is:
     * a check clever enough to find the simulator by convention would be
     * fooled by a class named to match, and deleting a simulator should break
     * this file at the import rather than at an assertion.
     *
     * @var array<string, array{simulator: class-string, real: ?class-string, contracts: list<class-string>}>
     */
    private const array FAMILIES = [
        'fake' => [
            'simulator' => FakeDnsProvider::class,
            'real' => CloudflareDnsProvider::class,
            'contracts' => [DnsProvider::class],
        ],
        'fake_bmc' => [
            'simulator' => FakeDedicatedProvider::class,
            // Redfish rather than iLO or IPMI: iLO extends it, and all three
            // implement the same interface. The parity question is about the
            // contract, and Redfish is the one that defines the shape.
            'real' => RedfishDedicatedProvider::class,
            'contracts' => [DedicatedProvider::class],
        ],
        'fake_compute' => [
            'simulator' => FakeComputeProvider::class,
            'real' => ProxmoxComputeProvider::class,
            'contracts' => [ComputeProvider::class],
        ],
        'fake_hosting' => [
            'simulator' => FakeHostingProvider::class,
            'real' => CpanelHostingProvider::class,
            'contracts' => [HostingProvider::class],
        ],
        'fake_wordpress' => [
            'simulator' => FakeHostingProvider::class,
            /*
             * No real adapter, and that is the finding rather than the
             * omission: neither cPanel nor DirectAdmin implements the
             * WordPress contracts in this repository, so the controlled
             * driver is the only implementation of either. Parity is
             * therefore measured against the interfaces alone, and the
             * report records the real side as NOT_IMPLEMENTED.
             */
            'real' => null,
            'contracts' => [WordPressInstaller::class, WordPressStagingProvider::class],
        ],
        'fake_backup' => [
            'simulator' => FakeBackupProvider::class,
            'real' => ProxmoxBackupProvider::class,
            'contracts' => [BackupProvider::class, FileLevelBackupProvider::class],
        ],
        'fake_rdns' => [
            'simulator' => FakeReverseDnsProvider::class,
            'real' => CloudflareReverseDnsProvider::class,
            'contracts' => [ReverseDnsProvider::class],
        ],
        'fake_registrar' => [
            'simulator' => FakeDomainRegistrarProvider::class,
            'real' => SyRegistryProvider::class,
            'contracts' => [DomainRegistrarProvider::class],
        ],
        'fake_payment' => [
            'simulator' => FakePaymentProvider::class,
            'real' => StripePaymentProvider::class,
            'contracts' => [PaymentProvider::class],
        ],
    ];

    /**
     * Public operations a real adapter has and its controlled driver does not,
     * with the reason each one cannot or need not be simulated.
     *
     * An entry here is a claim about the world, not a to-do: it has to say why
     * the operation is the real adapter's alone. The test below fails if an
     * entry names an operation the real adapter no longer has, or one the
     * simulator has since grown — a stale excuse is how the next real gap gets
     * excused too.
     *
     * @var array<string, array<string, string>>
     */
    private const array REAL_ONLY = [
        'fake_rdns' => [
            'ptrNameFor' => 'A pure derivation of the reverse zone name for an address — the reversed octets under '
                .'in-addr.arpa, or the nibble form under ip6.arpa. It is public and static so that its own unit '
                .'test can assert both forms directly, and it is not a provider operation: nothing calls it through '
                .'the contract, and the controlled driver has no zone hierarchy to find a name in.',
        ],
        'fake_payment' => [
            'webhookTolerance' => 'Stripe publishes its own replay window and the adapter reads it from the SDK. '
                .'The controlled gateway signs with a tolerance of its own and exposes it through the signing seam '
                .'rather than as a provider operation, so there is nothing for a caller to ask.',
        ],
    ];

    /**
     * Public methods a controlled driver has that no contract names, and what
     * each one is for.
     *
     * These are the seams that make a rehearsal possible: arranging the state
     * a real provider would already be in, and observing state a real provider
     * would only reveal through an operation the contract does not have. They
     * are not provider behaviour and nothing in `src/` calls them.
     *
     * @var array<string, array<string, string>>
     */
    private const array ARRANGEMENT = [
        'fake' => [
            'withZone' => 'Arranges a zone the account already holds. Holding a zone and being allowed to create '
                .'one are different capabilities, and a test that could only arrange the second — by calling '
                .'createZone — could never exercise the first, which is the ordinary case at every provider.',
        ],
        'fake_bmc' => [
            'isPxeArmed' => 'Observes whether the one-time override is still armed, so a test can prove it was '
                .'consumed by the reset rather than left behind. No BMC contract exposes the flag directly; '
                .'firmware reveals it only through the boot order, which is asserted separately, and a machine '
                .'that network boots for ever is the failure this observation exists to catch.',
            'addressWith' => 'Builds the address that makes this simulator behave a particular way — refuse, time '
                .'out, or report a dying disk — so a test states its intent instead of embedding a magic string '
                .'in an IP address, which is where nobody would think to look for it.',
        ],
        'fake_compute' => [
            'failingHostname' => 'Builds the hostname that makes this simulator refuse or fail a task, so a test '
                .'asking for the refusal branch says so in its own words instead of embedding "-provider-fail" in a '
                .'string. Selecting an outcome from the request is how every simulator here injects a fault; this '
                .'is the helper that keeps the marker in one place.',
        ],
        'fake_hosting' => [
            'credentialHandedTo' => 'Observes the password the panel was last handed for an account. The platform '
                .'mints a panel password per build and by design writes it nowhere it can read, and the contract '
                .'rightly has no operation that returns one — so a test that searches the platform\'s tables for '
                .'the credential it minted has nothing else to ask. Without it the only canary available is one '
                .'planted in a job payload, which the build no longer reads and the redactor destroys on write.',
        ],
        'fake_wordpress' => [
            'credentialHandedTo' => 'The hosting family\'s observation seam, listed again because one class '
                .'simulates both families and each family is checked on its own. See fake_hosting.',
        ],
        'fake_backup' => [],
        'fake_rdns' => [
            'publishedFor' => 'Observes the hostname this simulator believes is published for an address. The '
                .'contract has no read operation at all — deliberately — so a test proving a PTR was replaced '
                .'rather than appended has nothing else to ask.',
            'publishedCount' => 'The same observation, counted. The interface promises that publishing the same '
                .'address twice leaves one record and not two, and a count is the only way to assert a promise '
                .'about how many records exist.',
        ],
        'fake_registrar' => [
            'seedHolding' => 'Arranges a name the registrar already holds. A registration is the only other way '
                .'to get one, and it costs a term and an order, so a test of renewal, transfer or expiry would '
                .'otherwise have to rehearse a purchase first.',
            'forgetHolding' => 'The other half of the same case — a name the platform believes it holds and the '
                .'registrar has never heard of. A registrar contract has no operation for losing a name behind '
                .'its owner\'s back, and reconciliation exists for exactly that, so the seam is how a test '
                .'arranges it.',
        ],
        'fake_payment' => [
            'emitWebhook' => 'Builds the webhook the gateway would send, signed exactly as it would sign it, so '
                .'the platform\'s signature check — the most security-critical branch in the payments module — '
                .'runs for real against it rather than being bypassed by a test that posts an unsigned body.',
            'signPayload' => 'Signs an arbitrary body, so a test can sign one payload and deliver another and '
                .'watch the check fail. Also how a stale-but-otherwise-valid signature is produced, which is the '
                .'only way to prove the replay window is enforced.',
            'record' => 'Records the decision a person took on the controlled gateway page. It is the only '
                .'mutable state this simulator has, and it is what makes a browser authorisation visible to a '
                .'later server-side retrieve — without it the redirect journey could never be rehearsed at all.',
            'declineAmount' => 'Builds the amount that makes this gateway decline with a named code, so a test '
                .'states the decline it wants instead of hardcoding the arithmetic of the last two minor units.',
            'declineCodeFor' => 'Reads that mapping back the other way, so an assertion names the failure code the '
                .'platform will store rather than recomputing the amount arithmetic at the call site.',
            'authorisationUrlFor' => 'The page a redirecting browser is sent to. It points at the portal because '
                .'that is where the controlled gateway screen lives, and a real gateway\'s hosted page is not '
                .'something a JSON API can serve. A rehearsal surface, not a provider operation.',
            'nextActionShape' => 'Which confirmation shape this gateway is configured to ask for. Both shapes are '
                .'flows a real gateway uses, and a test has to be able to read back which one it was pointed at '
                .'so the portal branch it exercises is the one the configuration selected.',
            'clientSecretFor' => 'The client credential this gateway would have issued for an intent. The '
                .'controlled gateway screen compares what a browser presents against it, which is what makes the '
                .'client-confirmation path prove something: a browser that does not hold the credential cannot '
                .'confirm the payment. Derived rather than stored, exactly as the intent\'s own is.',
        ],
    ];

    #[Test]
    public function every_controlled_driver_has_a_simulator_that_implements_its_family_contract(): void
    {
        foreach (ControlledDriver::cases() as $driver) {
            $family = self::FAMILIES[$driver->value] ?? null;

            $this->assertNotNull($family, sprintf(
                '%s is catalogued as a controlled driver and this file does not know what simulates it. A '
                .'controlled driver with no simulator is a catalogue entry that lies.',
                $driver->value,
            ));

            foreach ($family['contracts'] as $contract) {
                $this->assertTrue(
                    is_a($family['simulator'], $contract, allow_string: true),
                    sprintf('%s does not implement %s, which its family contract requires.', $family['simulator'], $contract),
                );
            }
        }
    }

    #[Test]
    public function no_family_in_this_file_has_outlived_the_catalogue(): void
    {
        // The direction that rots silently: a controlled driver removed from
        // the catalogue leaves a family here claiming a rehearsal nobody can
        // reach.
        $this->assertSame([], array_diff(array_keys(self::FAMILIES), ControlledDriver::names()));
    }

    #[Test]
    public function the_controlled_driver_can_do_everything_the_real_adapter_can(): void
    {
        foreach (self::FAMILIES as $driver => $family) {
            if ($family['real'] === null) {
                continue;
            }

            $missing = array_values(array_diff(
                $this->operationsOf($family['real'], $family['contracts']),
                $this->operationsOf($family['simulator'], $family['contracts']),
                array_keys(self::REAL_ONLY[$driver] ?? []),
            ));

            $this->assertSame([], $missing, sprintf(
                "%s can do these things and %s cannot:\n  %s\n\nImplement them, or record in REAL_ONLY why the "
                ."operation is the real adapter's alone. A simulator missing an operation is a suite that goes "
                .'green over the gap.',
                $family['real'],
                $family['simulator'],
                implode("\n  ", $missing),
            ));
        }
    }

    #[Test]
    public function the_controlled_driver_invents_nothing_the_contract_does_not_name(): void
    {
        foreach (self::FAMILIES as $driver => $family) {
            $promised = [];

            foreach ($family['contracts'] as $contract) {
                $promised = [...$promised, ...$this->publicMethods($contract)];
            }

            /*
             * A simulator class may implement more than one family's
             * contracts — the hosting simulator is also the WordPress one —
             * so every interface it implements counts as promised here, not
             * only the ones this family names. What is being caught is an
             * operation no interface names at all.
             */
            foreach (class_implements($family['simulator']) ?: [] as $implemented) {
                $promised = [...$promised, ...$this->publicMethods($implemented)];
            }

            $invented = array_values(array_diff(
                $this->publicMethods($family['simulator']),
                $promised,
                array_keys(self::ARRANGEMENT[$driver] ?? []),
                // Every simulator refuses to exist in production from its own
                // constructor, which is not an operation.
                ['__construct'],
            ));

            $this->assertSame([], $invented, sprintf(
                "%s offers operations no contract names:\n  %s\n\nEither the platform cannot call them, in which "
                .'case a test proving they work proves nothing about the product, or the contract is missing them '
                .'and the interface is where to add them. A genuine arrangement seam goes in ARRANGEMENT with '
                .'what it is for.',
                $family['simulator'],
                implode("\n  ", $invented),
            ));
        }
    }

    #[Test]
    public function no_allow_list_entry_is_a_stale_excuse(): void
    {
        foreach (self::REAL_ONLY as $driver => $entries) {
            $family = self::FAMILIES[$driver];

            foreach ($entries as $operation => $reason) {
                $this->assertNotNull($family['real']);

                $this->assertTrue(
                    method_exists($family['real'], $operation),
                    sprintf('%s is excused for %s and %s no longer has it. Delete the excuse.', $operation, $driver, $family['real']),
                );

                $this->assertFalse(
                    method_exists($family['simulator'], $operation),
                    sprintf(
                        '%s is excused for %s and %s now implements it. Delete the excuse: an allow-list entry that '
                        .'is no longer true is how the next real gap gets excused too.',
                        $operation,
                        $driver,
                        $family['simulator'],
                    ),
                );

                $this->assertGreaterThan(
                    120,
                    mb_strlen($reason),
                    sprintf('The reason %s is not simulated for %s is too short to be a reason.', $operation, $driver),
                );
            }
        }

        foreach (self::ARRANGEMENT as $driver => $entries) {
            foreach ($entries as $operation => $reason) {
                $this->assertTrue(
                    method_exists(self::FAMILIES[$driver]['simulator'], $operation),
                    sprintf('%s is described as an arrangement seam on %s and does not exist.', $operation, $driver),
                );

                $this->assertGreaterThan(
                    120,
                    mb_strlen($reason),
                    sprintf('The description of the %s seam on %s is too short to say what it is for.', $operation, $driver),
                );
            }
        }
    }

    /**
     * The externally meaningful operations of a class.
     *
     * Public methods only, declared by the class or by one of the family's
     * contracts — a private helper is not an operation, and an inherited
     * framework method is not one either.
     *
     * @param  list<class-string>  $contracts
     * @return list<string>
     */
    private function operationsOf(string $class, array $contracts): array
    {
        $operations = $this->publicMethods($class);

        foreach ($contracts as $contract) {
            $operations = [...$operations, ...$this->publicMethods($contract)];
        }

        return array_values(array_unique(array_filter(
            $operations,
            static fn (string $method): bool => $method !== '__construct',
        )));
    }

    /**
     * @return list<string>
     */
    private function publicMethods(string $class): array
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        return array_values($methods);
    }
}
