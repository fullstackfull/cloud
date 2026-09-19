<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight\Checks;

use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\VerificationLevel;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Application\Services\ProbeProvider;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;

/**
 * One provider row, from its configuration down to what it will let us do.
 *
 * ===========================================================================
 * ROOT CAUSE FIRST, BY CONSTRUCTION
 * ===========================================================================
 *
 * The checks are a chain and the chain stops. That is not a style choice: a
 * preflight that ran every check independently would report, for one missing
 * credential reference, that the credential is missing, the identity is
 * unknown, the capabilities are unknown, the licence is unconfirmed and the
 * product is not ready — five findings, four of them derivative, and the
 * operator has to work out which one to act on.
 *
 * So each link runs only if the one before it passed, and everything below the
 * break is recorded as {@see CheckStatus::NotTested}
 * naming the link that stopped it. One blocker, four honest "we did not find
 * out", and the thing to go and do is at the top.
 *
 * The order is the order reality imposes:
 *
 *   configuration → credential → endpoint policy → identity → capabilities → licence
 *
 * A credential cannot be judged before there is an endpoint to judge it
 * against. An endpoint cannot be dialled before the policy has said it may be.
 * Capabilities cannot be read before something has authenticated. And a
 * licence question is only meaningful once the product has answered at all.
 *
 * ===========================================================================
 * WHAT NEVER HAPPENS HERE
 * ===========================================================================
 *
 * No write, in either mode. The probe this uses —
 * {@see ProbeProvider} — is the read-only half of the connection test,
 * extracted precisely so that a preflight cannot move the platform's own
 * state: a diagnosis that marked a credential Invalid while explaining why a
 * product cannot be sold would have changed the answer it was asked about.
 *
 * And no secret, ever, in any finding. The identity testers established that
 * discipline in Gap 2 and the findings here inherit it: every summary is built
 * from this file's own sentences plus values the testers already made safe.
 */
final readonly class ProviderChain
{
    public function __construct(
        private ProbeProvider $probe,
        private ProviderCatalogue $catalogue,
        private SafetyGate $gate,
    ) {}

    /**
     * @param  ManagedServer|null  $throughMachine  Set when this provider is being reached as a
     *                                              machine's controller rather than as an account
     *                                              in its own right. It changes which credential
     *                                              and which address are in play — see
     *                                              {@see self::credentialFor()}.
     * @return list<PreflightFinding>
     */
    public function inspect(ProviderInstance $provider, PreflightMode $mode, ?ManagedServer $throughMachine = null): array
    {
        $name = $provider->name;
        $findings = [];

        // ---- 1. configuration -------------------------------------------
        $entry = $this->catalogue->find($provider->driver);

        if ($entry === null) {
            /*
             * A row naming a driver the application does not have. The
             * catalogue is a list in the source precisely so this cannot
             * happen by editing a table, so reaching it means a seeder or an
             * import wrote a row nothing can serve.
             */
            return [PreflightFinding::fail(
                'provider.configuration',
                CheckCategory::Configuration,
                $name,
                sprintf('The row names driver "%s", which this build has no adapter for.', $provider->driver),
                sprintf('Change the provider to a catalogued driver, or remove the row. Catalogued drivers: %s.',
                    implode(', ', array_map(static fn ($e): string => $e->driver, $this->catalogue->entries()))),
            )];
        }

        if ($provider->state === ProviderState::Disabled) {
            /*
             * Not a failure. An operator disabled it, which is a decision, and
             * a preflight that reported somebody's deliberate act as a fault
             * would be arguing with them.
             */
            $findings[] = PreflightFinding::warning(
                'provider.configuration',
                CheckCategory::Configuration,
                $name,
                'The provider is disabled, so nothing downstream of it was checked.',
                'Enable the provider if it should be in service.',
            );

            return [...$findings, ...$this->notTestedBelow('provider.configuration', $name, 'credential')];
        }

        $findings[] = PreflightFinding::pass(
            'provider.configuration',
            CheckCategory::Configuration,
            $name,
            sprintf('A %s provider in the %s environment, state %s.', $provider->driver, $provider->environment->value, $provider->state->value),
            EvidenceClass::Configuration,
        );

        // ---- 1b. the machine underneath, where there is one ---------------
        if ($entry->needsEndpoint && $provider->category->needsServer()) {
            $machine = $this->machineFinding($provider, $name);

            $findings[] = $machine;

            if ($machine->status->blocking()) {
                return [...$findings, ...$this->notTestedBelow($machine->id, $name, 'credential')];
            }
        }

        // ---- 2. credential ------------------------------------------------
        $credential = $this->credentialFinding($provider, $entry->needsCredential, $name, $throughMachine);
        $findings[] = $credential;

        if ($credential->status->blocking()) {
            return [...$findings, ...$this->notTestedBelow($credential->id, $name, 'endpoint')];
        }

        // ---- 3. endpoint policy -------------------------------------------
        if ($entry->needsEndpoint && ($provider->endpoint === null || trim($provider->endpoint) === '')) {
            $findings[] = PreflightFinding::fail(
                'provider.endpoint',
                CheckCategory::Configuration,
                $name,
                'The driver needs an endpoint address and the row has none.',
                'Record the provider\'s HTTPS endpoint on the provider row.',
            );

            return [...$findings, ...$this->notTestedBelow('provider.endpoint', $name, 'identity')];
        }

        try {
            // Builds the target, which is what applies EndpointPolicy. Nothing
            // is dialled by this call.
            $this->probe->targetFor($provider);
        } catch (EndpointRefused $refused) {
            /*
             * The policy's own words. It refuses loopback, link-local,
             * multicast and the cloud metadata services, refuses a private
             * address for a provider that is somebody else's service, refuses
             * anything that is not HTTPS, and refuses a credential embedded in
             * a URL. Each refusal names which.
             */
            $findings[] = PreflightFinding::blocked(
                'provider.endpoint',
                CheckCategory::Network,
                $name,
                $refused->getMessage(),
                BlockerReason::Network,
                'Correct the endpoint address on the provider row.',
            );

            return [...$findings, ...$this->notTestedBelow('provider.endpoint', $name, 'identity')];
        }

        $findings[] = PreflightFinding::pass(
            'provider.endpoint',
            CheckCategory::Network,
            $name,
            'The endpoint passes the control plane\'s endpoint policy.',
            EvidenceClass::Configuration,
        );

        // ---- 4. identity ---------------------------------------------------
        $identity = $this->identityFinding($provider, $mode, $name, $throughMachine);
        $findings[] = $identity;

        if (! $identity->status->established()) {
            return [...$findings, ...$this->notTestedBelow($identity->id, $name, 'capabilities')];
        }

        // ---- 5. capabilities ------------------------------------------------
        $findings[] = $this->capabilityFinding($provider, $name);

        // ---- 6. licence -----------------------------------------------------
        $findings[] = $this->licenceFinding($provider, $entry->needsLicence, $name);

        return $findings;
    }

    /**
     * Whether the machine this provider runs on may be reached at all.
     *
     * `permits` rather than `assert`: a preflight reports, and a safety gate
     * that threw here would end the run instead of explaining it. The
     * classification is the estate owner's decision and the honest report is
     * that the machine is off limits, not that the provider is broken.
     */
    private function machineFinding(ProviderInstance $provider, string $name): PreflightFinding
    {
        $server = $provider->server;

        if ($server === null) {
            return PreflightFinding::blocked(
                'provider.machine',
                CheckCategory::Hardware,
                $name,
                'This provider runs on a machine of ours and no machine is bound to it.',
                BlockerReason::Hardware,
                'Register the machine and bind it to this provider.',
            );
        }

        if (! $this->gate->permits($server->safety_class, $server->allow_reimage, InfrastructureAction::Read)) {
            return PreflightFinding::blocked(
                'provider.machine',
                CheckCategory::Hardware,
                $name,
                sprintf('The machine %s is classified %s, which does not permit even a read.', $server->name, $server->safety_class->value),
                BlockerReason::Hardware,
                sprintf('Classify %s for at least discovery before anything reads from it.', $server->name),
            );
        }

        return PreflightFinding::pass(
            'provider.machine',
            CheckCategory::Hardware,
            $name,
            sprintf('Bound to machine %s, classified %s, which permits a read.', $server->name, $server->safety_class->value),
            EvidenceClass::Configuration,
        );
    }

    /**
     * Is there a credential, and may it be used here?
     *
     * Reported in the only vocabulary a credential check may use: present,
     * missing, invalid reference, environment mismatch, revoked, unknown.
     * Never a value, never a masked value, never a length.
     */
    private function credentialFinding(ProviderInstance $provider, bool $required, string $name, ?ManagedServer $throughMachine = null): PreflightFinding
    {
        $credential = $this->credentialFor($provider, $throughMachine);

        if (! $required) {
            return PreflightFinding::notApplicable(
                'provider.credential',
                CheckCategory::Credential,
                $name,
                'This driver needs no credential.',
            );
        }

        if ($credential === null) {
            return PreflightFinding::blocked(
                'provider.credential',
                CheckCategory::Credential,
                $name,
                'MISSING: no credential reference is attached to this provider.',
                BlockerReason::Credentials,
                'Record a credential reference in the credential centre and attach it to this provider.',
            );
        }

        if ($credential->state === CredentialState::Revoked) {
            return PreflightFinding::blocked(
                'provider.credential',
                CheckCategory::Credential,
                $name,
                sprintf('REVOKED: the credential reference "%s" has been revoked.', $credential->name),
                BlockerReason::Credentials,
                'Record a replacement credential and attach it.',
            );
        }

        if (! $credential->mayBeTried($provider->environment)) {
            /*
             * The cross-environment refusal, reported before anything is
             * dialled — and it is a refusal to *resolve*, so the value never
             * enters memory. A staging token is not tried against production,
             * not even to see what happens.
             */
            return PreflightFinding::blocked(
                'provider.credential',
                CheckCategory::Credential,
                $name,
                sprintf(
                    'ENVIRONMENT_MISMATCH: the credential "%s" is a %s credential and this is a %s provider. It was not resolved.',
                    $credential->name,
                    $credential->environment->value,
                    $provider->environment->value,
                ),
                BlockerReason::Credentials,
                sprintf('Attach a %s credential to this provider.', $provider->environment->value),
            );
        }

        if (! $this->probe->credentialExists($credential)) {
            return PreflightFinding::blocked(
                'provider.credential',
                CheckCategory::Credential,
                $name,
                sprintf(
                    'MISSING: the credential reference "%s" exists and the backend holds nothing behind it.',
                    $credential->name,
                ),
                BlockerReason::Credentials,
                sprintf('Set the %s variable on the deployment controller.', $credential->backend_reference),
            );
        }

        return PreflightFinding::pass(
            'provider.credential',
            CheckCategory::Credential,
            $name,
            sprintf('PRESENT: the credential reference "%s" resolves in this environment.', $credential->name),
            EvidenceClass::Configuration,
        );
    }

    /**
     * @throws NoSuchTester
     * @throws EndpointRefused
     */
    private function probeThroughMachine(ManagedServer $server): ConnectionResult
    {
        $target = $this->probe->targetForServer($server);

        return $this->probe->testerFor($target->driver, $target->environment)->test($target);
    }

    /**
     * The credential that will actually be sent, which is not always the
     * provider row's.
     *
     * A baseboard management controller is reached by two roads and they carry
     * different things. `TestConnection::forServer` resolves the **machine's**
     * credential and dials the machine's BMC address, because that is what a
     * managed server row holds; `forProvider` resolves the provider row's own.
     * Both are real paths in this platform.
     *
     * A preflight that only ever looked at the provider row would therefore
     * report "no credential" for a machine whose credential is recorded
     * exactly where the platform expects it — a false failure, and the most
     * annoying kind, because the operator can see the credential on the
     * screen.
     *
     * The provider's own credential wins when both exist, matching the
     * provider path; the machine's is the fallback, matching the machine path.
     */
    private function credentialFor(ProviderInstance $provider, ?ManagedServer $throughMachine): ?CredentialReference
    {
        return $provider->credential ?? $throughMachine?->credential;
    }

    /**
     * What is actually at the other end.
     *
     * The whole point of Gap 2 arrives here. The testers this calls establish
     * identity from something only the product says — a Proxmox build
     * envelope, WHM's echoed command, Cloudflare's own verification endpoint,
     * a Redfish service root's specification version — because in this
     * project's own sandbox a TLS handshake to a hostname that does not exist
     * completes and verifies. A socket opening is not a healthy connection,
     * and a preflight that treated it as one would report an estate that is
     * not there.
     */
    private function identityFinding(ProviderInstance $provider, PreflightMode $mode, string $name, ?ManagedServer $throughMachine = null): PreflightFinding
    {
        $controlled = in_array($provider->driver, $this->catalogue->controlledDrivers(), strict: true);

        /*
         * A controlled driver on a production row is blocked before the mode
         * is even consulted, and this branch exists because a deliberate
         * breakage proved the gate without it was vacuous.
         *
         * The earlier version reported a *warning* for a controlled driver in
         * real mode — "nothing about this is evidence about real
         * infrastructure" — and returned before probing. That reads as
         * reasonable and is far too soft: a warning does not block, so a
         * production row answered by a fake could carry a passing report. And
         * because the branch returned early, the guard in ProbeProvider was
         * never reached, so removing that guard changed nothing and the test
         * suite noticed nothing.
         *
         * A production row is what the readiness engine consults before a
         * product is offered for sale. There is no mode in which a fake may
         * answer for one.
         */
        if ($controlled && $provider->environment === DeploymentEnvironment::Production) {
            return PreflightFinding::blocked(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                sprintf(
                    'This is a production row using the controlled driver "%s". Nothing can establish whether a production provider is reachable through a driver that exists for rehearsal.',
                    $provider->driver,
                ),
                BlockerReason::NotImplemented,
                'Register a real driver for this production row.',
            );
        }

        if ($mode === PreflightMode::Simulation && ! $controlled) {
            /*
             * Simulation does not dial real providers. Not a limitation to
             * work around: a simulation that opened a socket to a customer's
             * cluster would be a real-mode run wearing the wrong label, and
             * the label is what people quote.
             */
            return PreflightFinding::notTested(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                sprintf('Simulation mode does not dial real providers, and %s is a real driver.', $provider->driver),
            );
        }

        if ($mode === PreflightMode::ReadOnlyReal && $controlled) {
            return PreflightFinding::warning(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                sprintf('This row uses the controlled driver "%s", so nothing about it is evidence about real infrastructure.', $provider->driver),
                'Replace the controlled driver with a real one before this row is relied on.',
                EvidenceClass::Simulation,
            );
        }

        if (! $this->probe->handles($provider->driver)) {
            return PreflightFinding::blocked(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                sprintf('No connection tester exists for the %s driver, so nothing can establish what is at that address.', $provider->driver),
                BlockerReason::NotImplemented,
                sprintf('Write an identity tester for the %s driver, or record why one cannot exist.', $provider->driver),
            );
        }

        try {
            /*
             * Through the same door the real connection test uses.
             *
             * A machine's own test dials the machine's BMC address with the
             * machine's credential; a provider row's test dials the row's
             * endpoint with the row's credential. Probing the provider row
             * here for a machine-bound controller would send a different
             * credential to a different address than the operation this
             * preflight is supposed to be predicting — and would then report
             * an authentication failure that the real path would never have.
             */
            $result = $throughMachine === null
                ? $this->probe->probe($provider)
                : $this->probeThroughMachine($throughMachine);
        } catch (NoSuchTester $refused) {
            return PreflightFinding::blocked(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                $refused->getMessage(),
                BlockerReason::NotImplemented,
                'Register a real driver for this provider row.',
            );
        }

        $simulated = $mode === PreflightMode::Simulation;
        $evidence = $simulated ? EvidenceClass::Simulation : EvidenceClass::RealRead;
        $state = $result->state;

        if ($state->usable()) {
            return PreflightFinding::pass(
                'provider.identity',
                CheckCategory::Identity,
                $name,
                sprintf('%s — %s.', $this->stateWord($state), $this->identityEvidence($result->steps)),
                $evidence,
                /*
                 * REAL_INFRA_VERIFIED for this read, and for nothing else.
                 *
                 * What a real provider answering an identity read establishes
                 * is that this platform can authenticate to it and read from
                 * it. It does not establish that a virtual machine will build,
                 * that a backup will restore, or that a payment will settle.
                 * Those are earned one at a time, by doing them, and this
                 * phase does none of them.
                 */
                verified: $simulated ? VerificationLevel::RuntimeVerified : VerificationLevel::RealInfraVerified,
            );
        }

        return PreflightFinding::blocked(
            'provider.identity',
            CheckCategory::Identity,
            $name,
            sprintf('%s. %s', $this->stateWord($state), $result->detail ?? $this->identityEvidence($result->steps)),
            $state->blocker() ?? BlockerReason::Network,
            $this->identityAction($state),
            $evidence,
        );
    }

    /**
     * What the provider said it can do, read from the capability rows the
     * connection test recorded.
     *
     * Preflight reads these and never writes them: recording a capability is
     * {@see TestConnection}'s
     * job, and a preflight that wrote one would be changing the estate's
     * description while describing it.
     */
    private function capabilityFinding(ProviderInstance $provider, string $name): PreflightFinding
    {
        $asked = $provider->category->capabilities();
        $observed = $provider->capabilities;

        if ($observed->isEmpty()) {
            return PreflightFinding::notTested(
                'provider.capabilities',
                CheckCategory::Capability,
                $name,
                sprintf(
                    'No capability has been observed on this provider yet. The driver supports %d capability question(s) in code; what this account will answer is unknown until a connection test records it.',
                    count($asked),
                ),
            );
        }

        $supported = $observed->filter(fn ($row): bool => $row->state === CapabilityState::Supported)->count();
        $unsupported = $observed->filter(fn ($row): bool => $row->state === CapabilityState::Unsupported)->count();
        $unknown = $observed->filter(fn ($row): bool => $row->state === CapabilityState::Unknown)->count();

        /*
         * Unknown is the honest answer for most capabilities and is not a
         * finding. Almost everything this platform does to a provider is a
         * write, and the only way to establish a write is to perform it — so a
         * connection test that reported every capability Supported would be
         * reporting things it could not know.
         */
        return PreflightFinding::pass(
            'provider.capabilities',
            CheckCategory::Capability,
            $name,
            sprintf(
                '%d capability(ies) offered, %d refused, %d unknown until used. Code supports %d question(s) for this category.',
                $supported,
                $unsupported,
                $unknown,
                count($asked),
            ),
            EvidenceClass::Configuration,
        );
    }

    /**
     * The licence, read from the licence centre rather than inferred.
     *
     * Deliberately not derived from a provider API answer. A panel that
     * answers its API is a panel whose licence is serving *now*; the expiry
     * date, the seat count and the renewal are recorded facts, and the
     * platform's only correct response to an expired licence is to stop
     * placing accounts and tell somebody to renew it.
     */
    private function licenceFinding(ProviderInstance $provider, bool $required, string $name): PreflightFinding
    {
        if (! $required) {
            return PreflightFinding::notApplicable(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                'This product needs no licence.',
            );
        }

        $licence = $provider->licence;

        if ($licence === null) {
            return PreflightFinding::blocked(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                'This driver is a licensed product and no licence is recorded for it.',
                BlockerReason::Licence,
                'Record the licence in the licence centre and attach it to this provider.',
            );
        }

        return match ($licence->state) {
            LicenceState::Active => PreflightFinding::pass(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                sprintf('The %s licence is active%s.', $licence->product, $licence->expires_on === null ? '' : ', expiring '.$licence->expires_on->toDateString()),
                EvidenceClass::Configuration,
            ),
            LicenceState::Expiring => PreflightFinding::warning(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                sprintf('The %s licence expires %s.', $licence->product, $licence->expires_on?->toDateString() ?? 'soon'),
                'Renew the licence with the vendor before it lapses.',
            ),
            LicenceState::NotRequired => PreflightFinding::notApplicable(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                'The recorded licence says none is required.',
            ),
            default => PreflightFinding::blocked(
                'provider.licence',
                CheckCategory::Licence,
                $name,
                sprintf('The %s licence is %s.', $licence->product, $licence->state->value),
                BlockerReason::Licence,
                'Renew or replace the licence with the vendor. No credential change will fix this.',
            ),
        };
    }

    /**
     * Everything below a break, recorded as not established.
     *
     * The link that broke is named in every one of them, so an operator
     * reading the fifth line still knows to act on the first.
     *
     * @return list<PreflightFinding>
     */
    private function notTestedBelow(string $brokenAt, string $name, string $from): array
    {
        $chain = [
            'credential' => ['provider.credential', CheckCategory::Credential],
            'endpoint' => ['provider.endpoint', CheckCategory::Network],
            'identity' => ['provider.identity', CheckCategory::Identity],
            'capabilities' => ['provider.capabilities', CheckCategory::Capability],
            'licence' => ['provider.licence', CheckCategory::Licence],
        ];

        $keys = array_keys($chain);
        $start = array_search($from, $keys, strict: true);

        if ($start === false) {
            return [];
        }

        $findings = [];

        foreach (array_slice($keys, (int) $start) as $key) {
            [$id, $category] = $chain[$key];

            $findings[] = PreflightFinding::notTested(
                $id,
                $category,
                $name,
                sprintf('Not established: %s did not pass, and asking this question anyway would have produced a second, derivative failure.', $brokenAt),
            );
        }

        return $findings;
    }

    /**
     * The state, in words, with the Gap 2 distinctions intact.
     *
     * Every one of these sends an operator somewhere different, which is why
     * none of them is "the provider is unavailable".
     */
    private function stateWord(ConnectionState $state): string
    {
        return match ($state) {
            ConnectionState::Connected => 'Connected',
            ConnectionState::ConnectedReadOnly => 'Connected, read-only',
            ConnectionState::AuthFailed => 'The product refused the credential',
            ConnectionState::IdentityMismatch => 'What answered is not this product',
            ConnectionState::CredentialMalformed => 'The stored credential is not in the shape this driver sends, so nothing was dialled',
            ConnectionState::NetworkFailed => 'Nothing answered',
            ConnectionState::TlsFailed => 'The certificate did not verify',
            ConnectionState::LicenceMissing => 'The product reports no valid licence',
            ConnectionState::PermissionInsufficient => 'Authenticated, and the account may not do what this platform needs',
            ConnectionState::ProviderUnavailable => 'The provider is having an outage',
            ConnectionState::NeedsReview => 'The answer could not be classified',
            ConnectionState::NotTested, ConnectionState::Testing => 'Not tested',
        };
    }

    private function identityAction(ConnectionState $state): string
    {
        return match ($state) {
            ConnectionState::AuthFailed => 'Rotate the credential at the provider and update the reference.',
            ConnectionState::IdentityMismatch => 'Correct the endpoint on the provider row. The credential was never judged, so do not rotate it.',
            ConnectionState::CredentialMalformed => 'Store the credential in the form this driver sends.',
            ConnectionState::NetworkFailed => 'Open the path from the deployment controller to this endpoint.',
            ConnectionState::TlsFailed => 'Add the endpoint\'s certificate authority to the controller\'s trust store. Do not disable verification.',
            ConnectionState::LicenceMissing => 'Renew the product licence with the vendor.',
            ConnectionState::PermissionInsufficient => 'Grant the account the role this platform needs. The credential itself is fine.',
            ConnectionState::ProviderUnavailable => 'Wait for the provider to recover, then run this again.',
            default => 'A person should look at the connection test\'s steps.',
        };
    }

    /**
     * The identity step's own words, which the tester already made safe.
     *
     * @param  list<ConnectionStep>  $steps
     */
    private function identityEvidence(array $steps): string
    {
        foreach ($steps as $step) {
            $row = $step->toArray();

            if ($row['name'] === 'identity' && isset($row['detail'])) {
                return $row['detail'];
            }
        }

        return 'no identity evidence was recorded';
    }
}
