<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Exceptions\NoBmcProvider;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;

/**
 * Ask something whether it answers, and change nothing at all.
 *
 * ===========================================================================
 * WHY THIS EXISTS, AND WHY IT IS AN EXTRACTION RATHER THAN A NEW THING
 * ===========================================================================
 *
 * {@see TestConnection} does
 * two jobs. It finds out whether a provider answers, and it writes down what
 * it found — and the writing is substantial: the provider row's connection
 * state and timestamps, every capability row, the credential's state and last
 * tested time, a `connection_tests` record, and an audit entry. All of that is
 * exactly right for an operator pressing "test connection", and all of it is
 * wrong for a preflight.
 *
 * A preflight observes. It must be safe to run on a schedule, safe to run
 * against a machine classified do-not-touch, and above all it must not move
 * the platform's own state — a preflight that marked a credential Invalid
 * while diagnosing why a product cannot be sold would have changed the answer
 * it was asked about.
 *
 * So the first job lives here and the second stays where it was. This is a
 * deduplication, not an addition: before it, a preflight would have had to
 * re-implement the endpoint policy call, the credential resolution rule and
 * the controlled-driver guard, and the copy would have drifted from the
 * original on the first change to either.
 *
 * ===========================================================================
 * WHAT IS PRESERVED EXACTLY
 * ===========================================================================
 *
 * Three rules move here unchanged, because each is a security property rather
 * than a convenience:
 *
 *   - **The endpoint is policed again at use**, not only at registration. A
 *     row can arrive by a seeder or an import, and the socket is what has to
 *     be guarded.
 *
 *   - **The credential is resolved as late as possible, and not at all when
 *     it belongs to another environment.** A refusal to resolve rather than a
 *     refusal to use: a staging token that happens to work against production
 *     never enters memory, so nothing downstream has the opportunity to send
 *     it by mistake.
 *
 *   - **A controlled driver may not answer for a production row.** The
 *     deployment-level controls cannot see that case, and it is the one that
 *     matters most, because a production row is what the readiness engine
 *     consults before a product is offered for sale.
 *
 * The driver is never a parameter. A caller that could name it could point a
 * probe — and the credential resolved for it — at any adapter the platform
 * has.
 */
final readonly class ProbeProvider
{
    public function __construct(
        private ConnectionTesterFactory $testers,
        private SecretResolver $secrets,
        private ProviderCatalogue $catalogue,
        private EndpointPolicy $endpoints,
        private Application $app,
    ) {}

    /**
     * Probe a provider account.
     *
     * @throws NoSuchTester
     * @throws EndpointRefused
     */
    public function probe(ProviderInstance $provider): ConnectionResult
    {
        $target = $this->targetFor($provider);

        return $this->testerFor($target->driver, $target->environment)->test($target);
    }

    /**
     * Everything a tester is allowed to know about a provider row.
     *
     * Public because a preflight needs to build the target without probing —
     * to report which capabilities are being asked about, and to establish
     * that a credential resolves at all, without opening a socket.
     *
     * @throws EndpointRefused
     */
    public function targetFor(ProviderInstance $provider): TestTarget
    {
        if ($provider->endpoint !== null && trim($provider->endpoint) !== '') {
            $this->endpoints->assertProviderEndpoint(
                $provider->endpoint,
                controlledDriver: in_array($provider->driver, $this->catalogue->controlledDrivers(), true),
                onOurHardware: $provider->category->needsServer(),
                production: $this->app->environment('production'),
            );
        }

        return new TestTarget(
            driver: $provider->driver,
            environment: $provider->environment,
            endpoint: $provider->endpoint,
            secret: $this->secretFor($provider->credential, $provider->environment),
            probeCapabilities: $provider->category->capabilities(),
            identity: $provider->name,
        );
    }

    /**
     * Everything a tester is allowed to know about a machine.
     *
     * Uses the BMC address when there is one and the management address
     * otherwise: during onboarding the BMC is usually reachable before the
     * operating system exists, which is the point of having one.
     *
     * @throws NoBmcProvider
     * @throws EndpointRefused
     */
    public function targetForServer(ManagedServer $server): TestTarget
    {
        $bmc = $server->bmc();

        if ($bmc === null) {
            throw NoBmcProvider::forServer($server->name);
        }

        $address = $server->bmc_address ?? $server->management_address;

        if ($address !== null) {
            $this->endpoints->assertMachineAddress($address, $this->app->environment('production'));
        }

        return new TestTarget(
            driver: $bmc->driver,
            environment: $server->environment,
            endpoint: $address,
            secret: $this->secretFor($server->credential, $server->environment),
            probeCapabilities: [],
            identity: $server->name,
        );
    }

    /**
     * The tester for a driver, if it may answer for this environment at all.
     *
     * The one seam every probe, every test and every discovery in this
     * platform resolves a tester through, which is why the environment check
     * lives here: the factory is handed a driver name and has no way to know
     * what it is being asked about.
     *
     * Raised as NoSuchTester so the refusal reaches an operator as the 422 the
     * connection test controller already renders for a driver nothing can
     * test. For a production row that is the true statement: the fake is
     * registered for rehearsal in this deployment, and there is nothing here
     * that can establish anything about a row the readiness engine consults
     * before a product goes on sale.
     *
     * @throws NoSuchTester
     */
    public function testerFor(string $driver, DeploymentEnvironment $environment): ConnectionTester
    {
        if ($environment === DeploymentEnvironment::Production
            && in_array($driver, $this->catalogue->controlledDrivers(), strict: true)) {
            throw NoSuchTester::forAProductionRow($driver);
        }

        return $this->testers->for($driver);
    }

    public function handles(string $driver): bool
    {
        return $this->testers->handles($driver);
    }

    /**
     * Does the backend hold anything behind this reference?
     *
     * The one question about a secret that may be answered over the network,
     * and the only one a preflight asks. It is deliberately not
     * `resolve() !== null`: that would put the value in a local for the length
     * of the comparison, and a preflight runs from request handling where a
     * stack trace is a response.
     *
     * Routed through this service rather than by handing a preflight the
     * resolver, so that the seam where a secret exists stays one seam.
     */
    public function credentialExists(CredentialReference $credential): bool
    {
        return $this->secrets->exists($credential->backend, $credential->backend_reference);
    }

    /**
     * The secret behind a reference, if it may be used here at all.
     *
     * mayBeTried, not mayServe. A credential only reaches Valid by being
     * tested, so demanding a valid one here would mean no newly configured
     * credential could ever be tested — every one would sit at Configured for
     * ever while the control centre reported an estate it had never contacted.
     *
     * The environment half of the rule is identical either way: a staging
     * token is not tried against production, not even to see what happens.
     */
    private function secretFor(?CredentialReference $credential, DeploymentEnvironment $environment): ?string
    {
        if ($credential === null || ! $credential->mayBeTried($environment)) {
            return null;
        }

        return $this->secrets->resolve($credential->backend, $credential->backend_reference);
    }
}
