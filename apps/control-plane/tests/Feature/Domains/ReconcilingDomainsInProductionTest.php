<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Domains\Application\Actions\ReconcileDomains;
use Lynomia\Modules\Domains\Domain\Contracts\DomainRegistrarProvider;
use Lynomia\Modules\Domains\Domain\DTOs\RegisteredDomain;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\DTOs\TransferStatus;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\FakeRegistrarInProductionException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Services\FakeRegistrarGuard;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Domains\Infrastructure\Providers\SyRegistryProvider;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * `domains:reconcile` on a production box, which is the only place it matters.
 *
 * ---------------------------------------------------------------------------
 * The failure this file exists for
 * ---------------------------------------------------------------------------
 *
 * The command runs every three hours. It settles the names the platform is
 * unsure of, prints what it settled, and then asks every registrar what it
 * holds that the platform has no row for. It used to ask every registrar the
 * build *contains* — a compile-time list that always includes the fake — and
 * the fake refuses to be constructed in production, by design. So on every
 * production run the useful half landed and reported, and the orphan scan
 * died on its first driver: a task that did most of its job and was red every
 * single time. That is the shape that teaches an operator to ignore it, after
 * which the run that genuinely fails looks identical to the hundreds before.
 *
 * So the first test here asserts in order — the name settled, the summary
 * printed, nothing was thrown — and before the repair the first two held and
 * the third did not. A failure downstream of a successful, reported settle
 * pass is the defect; "it throws" is only the smaller half of it.
 *
 * ---------------------------------------------------------------------------
 * What the repair is not allowed to be
 * ---------------------------------------------------------------------------
 *
 *  - **Not a weaker guard.** The fake stays unconstructible in production.
 *    Making it constructible so a console command stops throwing inverts the
 *    finding; the last two tests die under exactly that repair.
 *  - **Not catch-and-continue.** Swallowing whatever a driver throws cannot
 *    tell "not available here" from "broken", and broken is the failure most
 *    worth seeing. {@see self::a_driver_that_is_broken_rather_than_absent_still_fails_the_run()}.
 *  - **Not a quiet narrowing.** A run that did not ask every registrar says so,
 *    because "found no orphans" and "did not look everywhere" must not print
 *    the same line.
 *
 * ---------------------------------------------------------------------------
 * The stand-in registrar
 * ---------------------------------------------------------------------------
 *
 * The only real registrar this build has, {@see SyRegistryProvider}, refuses
 * everything (BLOCKED_LICENCE), and {@see ReconcileDomains::findOrphans()}
 * absorbs its refusal — correctly, because "could not look" is not "saw
 * nothing". Which means a test against the real class cannot tell "scanned
 * and found nothing" from "never scanned". {@see RegistrarStandIn} answers
 * under the same driver name so that the scan can be seen to happen. It is a
 * stand-in for *a registrar this build can talk to in production*, and says
 * nothing whatever about how the Syrian registry behaves.
 */
final class ReconcilingDomainsInProductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * One factory for the life of the test, so an adapter swapped in here
         * is the adapter the command's action is handed.
         */
        $this->app->singleton(DomainRegistrarFactory::class);
    }

    private function inProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    private function standIn(RegistrarStandIn $registrar): RegistrarStandIn
    {
        app(DomainRegistrarFactory::class)->swap($registrar);

        return $registrar;
    }

    /**
     * Runs the command and keeps whatever it printed, whether or not it threw.
     *
     * @return array{output: string, thrown: ?Throwable, exit: ?int}
     */
    private function reconcile(): array
    {
        $output = new BufferedOutput;
        $thrown = null;
        $exit = null;

        try {
            $exit = Artisan::call('domains:reconcile', [], $output);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return ['output' => $output->fetch(), 'thrown' => $thrown, 'exit' => $exit];
    }

    private function uncertain(string $name, string $provider): Domain
    {
        return Domain::factory()->create([
            'name' => $name,
            'tld' => 'test',
            'state' => DomainState::Indeterminate,
            'provider' => $provider,
        ]);
    }

    #[Test]
    public function the_orphan_scan_survives_production_after_the_settle_pass_has_landed(): void
    {
        $this->inProduction();

        $this->standIn(new RegistrarStandIn(holds: ['bought-in-the-dark.test']));
        $domain = $this->uncertain('bought-in-the-dark.test', SyRegistryProvider::NAME);

        $run = $this->reconcile();

        // In this order, deliberately. Before the repair the first two held
        // and the third did not: the settle pass landed, reported, and then
        // the orphan scan threw.
        $this->assertSame(DomainState::Active, $domain->fresh()?->state, 'the settle pass must land');
        $this->assertStringContainsString('Domains: 1 settled', $run['output'], 'the settle pass must report');
        $this->assertNull(
            $run['thrown'],
            'domains:reconcile threw in production after settling: '.($run['thrown']?->getMessage() ?? ''),
        );
        $this->assertSame(0, $run['exit']);
    }

    #[Test]
    public function the_real_registrar_seat_reconciles_in_production_with_no_doubles(): void
    {
        $this->inProduction();

        $domain = $this->uncertain('waiting-for-damascus.test', SyRegistryProvider::NAME);

        $run = $this->reconcile();

        $this->assertNull($run['thrown'], $run['thrown']?->getMessage() ?? '');
        $this->assertSame(0, $run['exit']);

        // The real seat cannot be asked what it holds, so the row stays for a
        // person — reported as unreachable, not guessed at.
        $this->assertStringContainsString(
            'Domains: 0 settled, 0 disagreements recorded, 1 registrars unreachable.',
            $run['output'],
        );
        $this->assertSame(DomainState::Indeterminate, $domain->fresh()?->state);

        // And the run says it did not look everywhere.
        $this->assertStringContainsString(
            '0 names held at a registrar with no row here, across 1 of 2 registrars (sy_registry).',
            $run['output'],
        );
    }

    #[Test]
    public function every_registrar_that_can_exist_here_is_still_asked_for_orphans_in_production(): void
    {
        $this->inProduction();

        $this->standIn(new RegistrarStandIn(holds: ['nobody-ordered-this.test']));

        $run = $this->reconcile();

        $this->assertNull($run['thrown'], $run['thrown']?->getMessage() ?? '');
        $this->assertStringContainsString('1 names held at a registrar with no row here', $run['output']);

        $drift = ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->sole();
        $this->assertSame(SyRegistryProvider::NAME, $drift->provider);
        $this->assertSame('domain', $drift->resource_type);
        $this->assertSame('nobody-ordered-this.test', $drift->provider_reference);
    }

    #[Test]
    public function outside_production_the_fake_is_asked_too_and_the_line_says_so(): void
    {
        app(DomainRegistrarFactory::class)
            ->make(FakeDomainRegistrarProvider::NAME)
            ->register(new RegistrationRequest('left-behind.test', 1, []));

        $run = $this->reconcile();

        $this->assertNull($run['thrown'], $run['thrown']?->getMessage() ?? '');
        $this->assertStringContainsString(
            '1 names held at a registrar with no row here, across 2 of 2 registrars (fake, sy_registry).',
            $run['output'],
        );

        $drift = ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->sole();
        $this->assertSame(FakeDomainRegistrarProvider::NAME, $drift->provider);
        $this->assertSame('left-behind.test', $drift->provider_reference);
    }

    #[Test]
    public function the_drivers_asked_are_the_drivers_listed_filtered_never_a_second_list(): void
    {
        $everywhere = DomainRegistrarFactory::drivers();

        $this->assertSame($everywhere, DomainRegistrarFactory::availableDrivers(), 'outside production nothing is left out');

        $this->inProduction();

        $here = DomainRegistrarFactory::availableDrivers();

        $this->assertSame(
            array_values(array_diff($everywhere, [FakeDomainRegistrarProvider::NAME])),
            $here,
            'in production exactly the fake is left out, and the order is the listed order',
        );
        $this->assertSame([], array_diff($here, $everywhere), 'nothing may be available that the build does not list');
    }

    #[Test]
    public function a_driver_that_is_broken_rather_than_absent_still_fails_the_run(): void
    {
        $this->inProduction();

        $broken = new RuntimeException('The registrar client could not be built.');
        $this->standIn(new RegistrarStandIn(listingThrows: $broken));

        $run = $this->reconcile();

        // Not available here is a fact about the environment and is reported.
        // Broken is a fault, and a scheduled run that swallows it is the
        // catch-and-continue repair this finding rejected.
        $this->assertSame($broken, $run['thrown'], 'a broken registrar must fail the run, not be skipped');
        $this->assertStringContainsString('Domains: 0 settled', $run['output']);
    }

    #[Test]
    public function a_production_row_that_names_the_fake_still_fails_loudly(): void
    {
        /*
         * Intended, not tolerated. A production row naming the fake is a
         * corrupted estate — the fake reports names as registered without
         * registering them, so that row is a paid order that does not exist —
         * and the right answer to it is to keep throwing. Recorded in the
         * ledger as an unnumbered observation about the Domains module, not
         * part of this finding. A repair that reached for the guard instead of
         * the loop would turn this green.
         */
        $this->inProduction();

        $domain = $this->uncertain('only-the-fake-knows.test', FakeDomainRegistrarProvider::NAME);

        $run = $this->reconcile();

        $this->assertInstanceOf(FakeRegistrarInProductionException::class, $run['thrown']);
        $this->assertSame(DomainState::Indeterminate, $domain->fresh()?->state, 'the fake must not have answered');
    }

    #[Test]
    public function the_fake_still_refuses_to_be_built_in_production(): void
    {
        $this->assertTrue(FakeRegistrarGuard::permitsTheFake(), 'outside production the fake is permitted');

        $this->inProduction();

        $this->assertFalse(FakeRegistrarGuard::permitsTheFake());

        try {
            new FakeDomainRegistrarProvider;
            $this->fail('the fake registrar was constructed in production');
        } catch (FakeRegistrarInProductionException $e) {
            $this->assertSame(['provider' => FakeDomainRegistrarProvider::NAME], $e->context());
        }

        $this->expectException(FakeRegistrarInProductionException::class);

        app(DomainRegistrarFactory::class)->make(FakeDomainRegistrarProvider::NAME);
    }

    #[Test]
    public function a_failed_scheduled_run_is_written_to_the_application_log(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $entries = array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => is_string($event->command)
                && preg_match('/artisan\S*.\s+\'?domains:reconcile\'?(\s|$)/', $event->command) === 1,
        ));

        $this->assertCount(1, $entries, 'domains:reconcile must be scheduled exactly once');
        $entry = $entries[0];
        $this->assertSame('35 */3 * * *', $entry->expression);

        Log::spy();

        $entry->exitCode = 0;
        $entry->callAfterCallbacks($this->app);

        Log::shouldNotHaveReceived('error');

        $entry->exitCode = 1;
        $entry->callAfterCallbacks($this->app);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context = []): bool => str_contains($message, 'domains:reconcile')
                && ($context['command'] ?? null) === 'domains:reconcile');
    }
}

/**
 * A registrar this build can talk to in production, answering as the one seat
 * it has. See the test class docblock for why it exists and what it is not.
 */
final class RegistrarStandIn implements DomainRegistrarProvider
{
    /**
     * @param  list<string>  $holds  names the stand-in holds, answered by both inspect() and heldNames()
     */
    public function __construct(
        private readonly array $holds = [],
        private readonly ?Throwable $listingThrows = null,
    ) {}

    public function name(): string
    {
        return SyRegistryProvider::NAME;
    }

    public function supports(RegistrarCapability $capability): bool
    {
        return $capability === RegistrarCapability::Inspection;
    }

    public function redemptionSupport(): RedemptionSupport
    {
        return RedemptionSupport::Unsupported;
    }

    public function supportedTlds(): array
    {
        return [];
    }

    public function checkAvailability(array $names): array
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function register(RegistrationRequest $request): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function renew(string $name, int $termYears): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function inspect(string $name): RegisteredDomain
    {
        if (! in_array($name, $this->holds, true)) {
            throw RegistrarNotAvailableException::forSyRegistry();
        }

        return new RegisteredDomain(
            name: $name,
            expiresAt: CarbonImmutable::now()->addYear(),
            providerReference: 'stand-in-'.$name,
            registeredAt: CarbonImmutable::now()->subDay(),
        );
    }

    public function setNameservers(string $name, array $nameservers): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function setContacts(string $name, array $contacts): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function setTransferLock(string $name, bool $locked): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function authorisationCode(string $name): string
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function startTransfer(string $name, string $authorisationCode, array $nameservers = []): TransferStatus
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function transferStatus(string $name): TransferStatus
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function redeem(string $name): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function heldNames(): array
    {
        if ($this->listingThrows !== null) {
            throw $this->listingThrows;
        }

        return $this->holds;
    }
}
