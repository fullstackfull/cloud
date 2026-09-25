<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Infrastructure\Application\Naming\AuditInfrastructureNaming;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\DependencyChain;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\MappingChain;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\ProviderChain;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\ReservedZonesCheck;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\Requirement;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Application\Services\ProbeProvider;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use Throwable;

/**
 * The one place that answers: what exactly prevents this from being used?
 *
 * ===========================================================================
 * WHAT THIS IS AND IS NOT
 * ===========================================================================
 *
 * It is orchestration. Every fact it reports comes from something Phase 30B-P
 * already built — the provider registry, the credential centre, the endpoint
 * policy, the Gap 2 identity testers, the capability rows, the licence centre,
 * the machine classifications, the compute and hosting inventories, the Gap 1
 * backup producer, the metrics registry, and the one product readiness engine.
 * It owns none of them and duplicates none of them.
 *
 * It exists because those truths were only ever reachable one screen at a
 * time. An operator asking "why can I not sell a VPS" had to open the provider
 * list, the credential centre, the licence centre, the cluster inventory and
 * the readiness page, and hold the answer in their head. That question now has
 * one caller, and the CLI and the Admin API are two presentations of it rather
 * than two implementations.
 *
 * ===========================================================================
 * WHY NOTHING HERE CAN WRITE
 * ===========================================================================
 *
 * Not by convention. By what it is given:
 *
 *   - {@see ProbeProvider} is
 *     the read-only half of the connection test, extracted for this. The
 *     recording half — the provider row's state, the capability rows, the
 *     credential's state, the connection_tests record, the audit entry — is
 *     not reachable from here, because the action that does it is not
 *     injected.
 *   - {@see AssessProduct::verdictFor()} computes a verdict and does not
 *     persist one. The persisting method is private.
 *   - {@see SafetyGate::permits()} is asked, never `assert` — a preflight
 *     reports a refusal, it does not raise one.
 *   - No mutating provider contract, no deployment action, no desired-state
 *     writer and no IaC bridge is a constructor dependency, so no check can
 *     reach one.
 *
 * `NoPreflightCodePathCanWriteTest` asserts the negative across every file in
 * this namespace, and a deliberate breakage in the suite proves the assertion
 * fails when a write is added.
 *
 * ===========================================================================
 * FAILURE ISOLATION AND THE DEADLINE
 * ===========================================================================
 *
 * One provider that hangs must not hang the estate, and one provider that
 * throws must not end the run. Each target is wrapped: a throw becomes a
 * finding naming the target, and the run continues with the next one. And the
 * run carries a deadline — once it passes, remaining targets are recorded as
 * not established rather than dialled, so a global run in real mode has a
 * bound an operator can predict.
 *
 * There is no concurrency here, deliberately. This codebase has no convention
 * for concurrent outbound HTTP, and introducing one inside a preflight — the
 * thing that is supposed to be the safest operation in the platform — would
 * be the wrong place to try it.
 */
final readonly class InfrastructurePreflightService
{
    /**
     * How long a whole run may take before it stops starting new checks.
     *
     * Not a per-check timeout: the identity testers each carry their own
     * 15-second deadline, and this bounds the sum. Chosen so that an
     * Admin-triggered run answers inside a request rather than inside a
     * patience threshold.
     */
    private const int DEADLINE_SECONDS = 120;

    public function __construct(
        private ProviderChain $providers,
        private MappingChain $mappings,
        private DependencyChain $dependencies,
        private AssessProduct $readiness,
        private ProductRequirements $requirements,
        private SafetyGate $gate,
        private AuditInfrastructureNaming $naming,
        private ReservedZonesCheck $reservedZones,
        private ReferenceValues $reference = new ReferenceValues,
    ) {}

    public function run(PreflightRequest $request): PreflightReport
    {
        $startedAt = CarbonImmutable::now();
        $deadline = $startedAt->addSeconds(self::DEADLINE_SECONDS);

        /*
         * Read fresh, every run, and read once.
         *
         * Fresh because a preflight an operator asked for has to be a
         * preflight: showing them a cached success as a newly executed run is
         * the one thing a diagnostic must never do. Once because the database
         * work has to stay bounded as the estate grows — the provider rows and
         * their credentials, licences, machines and capabilities are loaded in
         * one eager query here rather than lazily per check, which is what
         * keeps the query count flat in the budget test.
         */
        $providers = ProviderInstance::query()
            ->with(['credential', 'licence', 'server', 'capabilities'])
            ->get();

        $findings = match ($request->scope) {
            PreflightScope::Estate => $this->estate($request, $providers, $deadline),
            PreflightScope::Site => $this->site($request, $providers, $deadline),
            PreflightScope::Provider => $this->provider($request, $providers),
            PreflightScope::Product => $this->product($request, $providers, $deadline),
            PreflightScope::Machine => $this->machine($request, $providers),
        };

        return new PreflightReport(
            mode: $request->mode,
            scope: $request->scope,
            target: $request->target,
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
            findings: $findings,
            referenceTopology: $this->sawTheReferenceTopology($providers),
        );
    }

    /**
     * Did this run look at rows out of the reference topology?
     *
     * Computed from the rows the run actually loaded rather than by asking
     * whether a reference topology exists on disk — the question the report has
     * to answer is what these checks were run against, not what the repository
     * happens to contain. Provider names, provider endpoints and the machines
     * behind them are the identifiers the loader writes, and they all carry the
     * `ref-` scheme, which is exactly why the scheme is a scheme.
     *
     * @param  Collection<int, ProviderInstance>  $providers
     */
    private function sawTheReferenceTopology(Collection $providers): bool
    {
        foreach ($providers as $provider) {
            $candidates = array_filter([$provider->name, $provider->endpoint, $provider->server?->name]);

            foreach ($candidates as $candidate) {
                if ($this->reference->refuseForProduction($candidate) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The whole estate: every provider, every product family, the shared
     * dependencies.
     *
     * Product families are reported separately and never averaged. One family
     * being sellable while another is blocked is the normal state of a
     * platform being brought up, and a single aggregate green would hide it.
     *
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function estate(PreflightRequest $request, Collection $providers, CarbonImmutable $deadline): array
    {
        $findings = [];

        foreach ($providers as $provider) {
            $findings = [...$findings, ...$this->guarded(
                $provider->name,
                CheckCategory::Configuration,
                fn (): array => $this->withDeadline($deadline, $provider->name, fn (): array => $this->providers->inspect($provider, $request->mode)),
            )];
        }

        if ($providers->isEmpty()) {
            $findings[] = PreflightFinding::blocked(
                'provider.none',
                CheckCategory::Configuration,
                'the estate',
                'No provider is registered, so nothing can be provisioned through anything.',
                BlockerReason::Configuration,
                'Register a provider in the control centre and attach a credential to it.',
            );
        }

        $findings = [...$findings, ...$this->guarded('dependencies', CheckCategory::Backup, fn (): array => $this->dependencies->inspect())];

        $findings = [...$findings, ...$this->guarded('naming', CheckCategory::Configuration, fn (): array => $this->namingFindings($request))];

        /*
         * Deployment-wide like the two above, and estate-only unlike the
         * first: which names no account may claim is a fact about this
         * deployment's configuration and about no one product, so a product
         * run would repeat it without adding anything.
         */
        $findings = [...$findings, ...$this->guarded('dns', CheckCategory::Configuration, fn (): array => $this->reservedZones->inspect())];

        foreach ($this->familiesInService() as $product) {
            $findings = [...$findings, ...$this->guarded(
                $product->value,
                CheckCategory::Readiness,
                fn (): array => $this->productFindings($product, $providers),
            )];
        }

        return $findings;
    }

    /**
     * One datacenter, and the providers whose machines are in it.
     *
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function site(PreflightRequest $request, Collection $providers, CarbonImmutable $deadline): array
    {
        $datacenter = Datacenter::query()
            ->where('slug', $request->target)
            ->orWhere('id', $request->target)
            ->first();

        if ($datacenter === null) {
            return [PreflightFinding::fail(
                'site.exists',
                CheckCategory::Configuration,
                (string) $request->target,
                'No datacenter is registered under that name.',
                'Register the datacenter, or run the preflight against one that exists.',
            )];
        }

        $servers = ManagedServer::query()
            ->where('datacenter_id', $datacenter->getKey())
            ->get();

        if ($servers->isEmpty()) {
            return [PreflightFinding::blocked(
                'site.machines',
                CheckCategory::Hardware,
                $datacenter->slug,
                sprintf('The datacenter %s is registered and holds no machine.', $datacenter->slug),
                BlockerReason::Hardware,
                'Rack and register a machine in this datacenter.',
            )];
        }

        $findings = [PreflightFinding::pass(
            'site.machines',
            CheckCategory::Hardware,
            $datacenter->slug,
            sprintf('%d machine(s) registered in %s.', $servers->count(), $datacenter->slug),
            EvidenceClass::Configuration,
        )];

        $inSite = $providers->filter(
            static fn (ProviderInstance $provider): bool => $provider->managed_server_id !== null
                && $servers->contains(static fn (ManagedServer $server): bool => $server->getKey() === $provider->managed_server_id),
        );

        foreach ($inSite as $provider) {
            $findings = [...$findings, ...$this->guarded(
                $provider->name,
                CheckCategory::Configuration,
                fn (): array => $this->withDeadline($deadline, $provider->name, fn (): array => $this->providers->inspect($provider, $request->mode)),
            )];
        }

        return $findings;
    }

    /**
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function provider(PreflightRequest $request, Collection $providers): array
    {
        $provider = $providers->first(
            static fn (ProviderInstance $row): bool => $row->name === $request->target
                || $row->getKey() === $request->target,
        );

        if ($provider === null) {
            return [PreflightFinding::fail(
                'provider.exists',
                CheckCategory::Configuration,
                (string) $request->target,
                'No provider is registered under that name.',
                'Register the provider, or run the preflight against one that exists.',
            )];
        }

        return $this->guarded(
            $provider->name,
            CheckCategory::Configuration,
            fn (): array => $this->providers->inspect($provider, $request->mode),
        );
    }

    /**
     * One product: its mappings, its dependencies, its providers, and the
     * readiness engine's verdict on all of it.
     *
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function product(PreflightRequest $request, Collection $providers, CarbonImmutable $deadline): array
    {
        $product = Product::tryFrom((string) $request->target);

        if ($product === null) {
            return [PreflightFinding::fail(
                'product.exists',
                CheckCategory::Readiness,
                (string) $request->target,
                'That is not a product this platform knows about.',
                sprintf('Name one of: %s.', implode(', ', array_column(Product::cases(), 'value'))),
            )];
        }

        $findings = $this->guarded($product->value, CheckCategory::Mapping, fn (): array => $this->mappings->inspect($product));

        /*
         * Only the providers this product actually needs. A VPS preflight that
         * dialled the registrar would take longer and tell the operator
         * nothing about virtual machines.
         */
        $categories = array_map(
            static fn (Requirement $requirement): string => $requirement->category->value,
            $this->requirements->for($product),
        );

        foreach ($providers->whereIn('category', $categories) as $provider) {
            $findings = [...$findings, ...$this->guarded(
                $provider->name,
                CheckCategory::Configuration,
                fn (): array => $this->withDeadline($deadline, $provider->name, fn (): array => $this->providers->inspect($provider, $request->mode)),
            )];
        }

        $findings = [...$findings, ...$this->guarded('dependencies', CheckCategory::Backup, fn (): array => $this->dependencies->inspect())];

        return [...$findings, ...$this->guarded($product->value, CheckCategory::Readiness, fn (): array => $this->productFindings($product, $providers))];
    }

    /**
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function machine(PreflightRequest $request, Collection $providers): array
    {
        $server = ManagedServer::query()
            ->where('name', $request->target)
            ->orWhere('id', $request->target)
            ->first();

        if ($server === null) {
            return [PreflightFinding::fail(
                'machine.exists',
                CheckCategory::Hardware,
                (string) $request->target,
                'No machine is registered under that name.',
                'Register the machine, or run the preflight against one that exists.',
            )];
        }

        $findings = [
            PreflightFinding::pass(
                'machine.registered',
                CheckCategory::Hardware,
                $server->name,
                sprintf('Registered, state %s, classified %s.', $server->state->value, $server->safety_class->value),
                EvidenceClass::Configuration,
            ),
        ];

        $findings[] = $this->gate->permits($server->safety_class, $server->allow_reimage, InfrastructureAction::Read)
            ? PreflightFinding::pass(
                'machine.classification',
                CheckCategory::Hardware,
                $server->name,
                sprintf('The %s classification permits a read.', $server->safety_class->value),
                EvidenceClass::Configuration,
            )
            : PreflightFinding::blocked(
                'machine.classification',
                CheckCategory::Hardware,
                $server->name,
                sprintf('The %s classification does not permit even a read, so nothing was asked of this machine.', $server->safety_class->value),
                BlockerReason::Hardware,
                sprintf('Classify %s for at least discovery.', $server->name),
            );

        $bmc = $server->bmc();

        if ($bmc === null) {
            $findings[] = PreflightFinding::blocked(
                'machine.bmc',
                CheckCategory::Hardware,
                $server->name,
                'No baseboard management controller is bound to this machine, so it cannot be powered or reinstalled.',
                BlockerReason::Hardware,
                'Register the machine\'s controller as a provider and bind it to the machine.',
            );

            return $findings;
        }

        /*
         * Inspected as the machine's controller, not as a standalone account.
         * That distinction decides which credential is in play: a managed
         * server's own connection test resolves the machine's credential, and
         * a preflight that looked only at the provider row would report a
         * missing credential for a machine that has one.
         */
        return [...$findings, ...$this->guarded(
            $bmc->name,
            CheckCategory::Identity,
            fn (): array => $this->providers->inspect($bmc, $request->mode, throughMachine: $server),
        )];
    }

    /**
     * The readiness engine's verdict, turned into findings.
     *
     * Consulted, never reimplemented. There is no `canSellVps()` here and
     * there will not be one: the ladder, the dependency recursion, the
     * capability gate and the sellability declaration all live in
     * {@see ProductReadinessEvaluator},
     * and a second opinion would eventually disagree with the one the order
     * pipeline actually enforces.
     *
     * Nothing is persisted. `verdictFor` exists so that a diagnosis cannot
     * change the state of the thing being diagnosed.
     *
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<PreflightFinding>
     */
    private function productFindings(Product $product, Collection $providers): array
    {
        $verdict = $this->readiness->verdictFor($product, $providers);

        $findings = [];

        foreach ($verdict->requirements as $requirement) {
            $findings[] = $this->requirementFinding($product, $requirement);
        }

        foreach ($verdict->dependencies as $dependency => $state) {
            $findings[] = $state === ProductReadinessState::NotReady->value
                ? PreflightFinding::fail(
                    'readiness.dependency',
                    CheckCategory::Readiness,
                    $product->value,
                    sprintf('This product depends on %s, which is not ready.', $dependency),
                    sprintf('Run the preflight against %s and clear its blockers first.', $dependency),
                )
                : PreflightFinding::pass(
                    'readiness.dependency',
                    CheckCategory::Readiness,
                    $product->value,
                    sprintf('Depends on %s, which is %s.', $dependency, $state),
                    EvidenceClass::Configuration,
                );
        }

        /*
         * The rung, and the distance to selling.
         *
         * ready_to_sell is never reached by a preflight and never by the
         * engine: it is a person's declaration, applied by the action that
         * records a verdict. A preflight reports the rung below it and says
         * so, because a preflight that implied it could make something
         * sellable would be the most dangerous sentence in this file.
         */
        $findings[] = $verdict->state === ProductReadinessState::ReadyForProduction
            ? PreflightFinding::pass(
                'readiness.product',
                CheckCategory::Readiness,
                $product->value,
                'Ready for production. Selling it is a declaration a person makes, which this preflight does not make and cannot.',
                EvidenceClass::Configuration,
            )
            : PreflightFinding::blocked(
                'readiness.product',
                CheckCategory::Readiness,
                $product->value,
                sprintf('%s: %s', $verdict->state->value, $verdict->detail ?? 'the readiness engine reports this product is not ready for production.'),
                $verdict->blocker ?? BlockerReason::Configuration,
                'Clear the requirement blockers listed above for this product, then run this again.',
            );

        return $findings;
    }

    private function requirementFinding(Product $product, RequirementVerdict $requirement): PreflightFinding
    {
        $category = $requirement->requirement->category->value;

        if ($requirement->blocker === null) {
            return PreflightFinding::pass(
                'readiness.requirement',
                CheckCategory::Readiness,
                sprintf('%s / %s', $product->value, $category),
                sprintf('Satisfied up to %s by %s.', $requirement->satisfiedUpTo->value, $requirement->providerName ?? 'a provider'),
                EvidenceClass::Configuration,
            );
        }

        /*
         * The action is written here rather than taken from
         * BlockerReason::nextAction(), which returns a translation key for the
         * Control Center to render — and a key printed by the CLI is not an
         * instruction. Naming the category makes it more useful anyway: an
         * operator reading "no compute provider satisfies this" knows which of
         * six requirements to go and look at.
         */
        return PreflightFinding::blocked(
            'readiness.requirement',
            CheckCategory::Readiness,
            sprintf('%s / %s', $product->value, $category),
            $requirement->detail,
            $requirement->blocker,
            sprintf(
                'Resolve the %s requirement for %s: %s.',
                $category,
                $product->value,
                $this->requirementAdvice($requirement->blocker),
            ),
        );
    }

    /**
     * What each blocker reason means for a product requirement, in words.
     *
     * Deliberately a second set of words rather than a reuse of
     * BlockerReason::nextAction(): that method's answers are keys, and they
     * are written for a provider screen. These are written for the question
     * "why can this product not be sold".
     */
    private function requirementAdvice(BlockerReason $blocker): string
    {
        return match ($blocker) {
            BlockerReason::Credentials => 'attach a credential the controller holds to a provider in that category',
            BlockerReason::Hardware => 'register and classify the machine that category runs on',
            BlockerReason::Licence => 'record a valid licence for that category\'s provider',
            BlockerReason::Network => 'correct the endpoint, or open the path from the controller to it',
            BlockerReason::Configuration => 'register a provider in that category and finish configuring it',
            /*
             * On a requirement, Dependency means the provider that should
             * satisfy it is absent or not ready — not that another product is
             * in the way. The provider-screen wording would send an operator
             * looking for a dependent product that does not exist.
             */
            BlockerReason::Dependency => 'register a provider in that category, or bring the existing one up to production readiness',
            BlockerReason::NotImplemented => 'this platform has no adapter for that category yet',
        };
    }

    /**
     * What the naming standard says about this estate, in the report's own
     * vocabulary.
     *
     * The checks are not reimplemented here. Gap 5 put them in one service
     * because the CLI, the preflight and the tests all have to agree about
     * what a noncanonical identifier is, and three copies of that judgement
     * would eventually be three answers. This maps its findings into the
     * report's terms and nothing more.
     *
     * A naming problem is a CONFIGURATION finding. It is not a new blocker
     * category: an operator reading "blocked: configuration — two racks in
     * this datacenter are one identity" knows what to do, and a
     * `BLOCKED_NAMING` would mean teaching every consumer of this report a
     * word that adds nothing.
     *
     * @return list<PreflightFinding>
     */
    private function namingFindings(PreflightRequest $request): array
    {
        $production = $request->mode === PreflightMode::ReadOnlyReal
            && app()->environment('production');

        return array_map(
            static fn (NamingFinding $finding): PreflightFinding => match ($finding->status) {
                CheckStatus::Fail, CheckStatus::Blocked => PreflightFinding::blocked(
                    $finding->id,
                    CheckCategory::Configuration,
                    $finding->target,
                    $finding->summary,
                    BlockerReason::Configuration,
                    $finding->nextAction ?? 'Resolve the naming conflict this names.',
                ),
                CheckStatus::Warning => PreflightFinding::warning(
                    $finding->id,
                    CheckCategory::Configuration,
                    $finding->target,
                    $finding->summary,
                    $finding->nextAction,
                ),
                default => PreflightFinding::pass(
                    $finding->id,
                    CheckCategory::Configuration,
                    $finding->target,
                    $finding->summary,
                    EvidenceClass::Configuration,
                ),
            },
            $this->naming->execute($production),
        );
    }

    /**
     * Product families to report on in a global run.
     *
     * The five the platform sells, and only those. The prepared categories —
     * CDN, object storage and the rest — have no adapter, and listing them as
     * blocked in every global run would train an operator to ignore blockers.
     *
     * @return list<Product>
     */
    private function familiesInService(): array
    {
        return [Product::Vps, Product::Dedicated, Product::SharedHosting, Product::WordPress, Product::Domains];
    }

    /**
     * Run one target's checks, and turn a throw into a finding.
     *
     * A preflight is a diagnostic: it is run precisely when something is
     * wrong, so it is exactly the code most likely to meet an unexpected
     * state. One provider whose row is malformed enough to throw must not cost
     * the operator the other eleven answers.
     *
     * The exception's class is recorded and its message is not. A message from
     * deep inside an HTTP client or a database driver can carry an endpoint, a
     * resolved address or a query, and this string is persisted, rendered and
     * audited.
     *
     * @param  callable(): list<PreflightFinding>  $checks
     * @return list<PreflightFinding>
     */
    private function guarded(string $target, CheckCategory $category, callable $checks): array
    {
        try {
            return $checks();
        } catch (Throwable $failure) {
            return [PreflightFinding::fail(
                'preflight.check_failed',
                $category,
                $target,
                sprintf('A check against this target could not complete (%s). The rest of the preflight continued.', class_basename($failure)),
                'Look at this target in the control centre; the preflight could not read it cleanly.',
            )];
        }
    }

    /**
     * @param  callable(): list<PreflightFinding>  $checks
     * @return list<PreflightFinding>
     */
    private function withDeadline(CarbonImmutable $deadline, string $target, callable $checks): array
    {
        if (CarbonImmutable::now()->greaterThan($deadline)) {
            return [PreflightFinding::notTested(
                'preflight.deadline',
                CheckCategory::Configuration,
                $target,
                sprintf('Not established: the preflight\'s %d second budget passed before this target was reached.', self::DEADLINE_SECONDS),
            )];
        }

        return $checks();
    }
}
