<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Services;

use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\DTOs\ReadinessVerdict;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * Why a provider is not usable yet, in the order the answers can be acted on.
 *
 * ---------------------------------------------------------------------------
 * The order is the design
 * ---------------------------------------------------------------------------
 *
 * Hardware, then licence, then credentials, then network, then configuration —
 * the order {@see BlockerReason::first()} encodes, and it is dependency order
 * rather than severity. There is no point telling somebody their credential is
 * unproven when the machine it authenticates against has not been classified
 * as touchable: they cannot test it, so the credential blocker is not
 * actionable and the hardware one is.
 *
 * ---------------------------------------------------------------------------
 * What "ready" costs
 * ---------------------------------------------------------------------------
 *
 * ReadyForProduction requires that something actually answered — a usable
 * connection state — and that its capabilities were discovered. Neither is
 * inferred. A provider whose credential is configured, whose licence is in
 * date and which nobody has ever contacted is ReadyForTest, and enabling it is
 * refused. This is the whole reason the phase exists: the platform has three
 * times shipped a capability that was present in code and could not complete
 * in the product, and every one of them would have passed a check that stopped
 * at "the configuration looks complete".
 */
final readonly class ProviderReadiness
{
    /**
     * @param  bool  $testerAvailable  Whether a connection tester exists for this driver.
     *                                 Passed in rather than looked up so this stays a pure
     *                                 function of stated facts, and so the domain does not
     *                                 reach for the container to answer a question about
     *                                 what is registered in it.
     */
    public function assess(CatalogueEntry $entry, ProviderInstance $provider, bool $testerAvailable): ReadinessVerdict
    {
        $blocked = fn (BlockerReason $reason, string $detail): ReadinessVerdict => new ReadinessVerdict(
            ReadinessState::NotReady,
            $reason,
            $detail,
        );

        if ($entry->needsServer()) {
            $server = $provider->server;

            if ($server === null) {
                return $blocked(BlockerReason::Hardware, 'Runs on a machine we manage, and no machine is assigned.');
            }

            /*
             * The safety classification is a readiness input, not just a guard.
             *
             * A do_not_touch machine cannot be connected to, so a provider on
             * it can never be tested and can never become ready. Reporting
             * that as a hardware blocker sends an operator to the decision
             * that actually unblocks it, rather than to a connection test that
             * will be refused.
             */
            if (! $server->isTouchable()) {
                return $blocked(
                    BlockerReason::Hardware,
                    sprintf('%s is classified %s, so nothing may connect to it.', $server->name, $server->safety_class->value),
                );
            }
        }

        if ($entry->needsLicence) {
            $licence = $provider->licence;

            if ($licence === null) {
                return $blocked(BlockerReason::Licence, 'This product requires a licence and none is recorded.');
            }

            if (! $licence->environment->satisfies($provider->environment)) {
                return $blocked(
                    BlockerReason::Licence,
                    sprintf('The licence is for %s and this provider is %s.', $licence->environment->value, $provider->environment->value),
                );
            }

            if (! $licence->state->permits()) {
                return $blocked(BlockerReason::Licence, sprintf('The licence is %s.', $licence->state->value));
            }
        }

        if ($entry->needsCredential) {
            $credential = $provider->credential;

            if ($credential === null) {
                return $blocked(BlockerReason::Credentials, 'No credential is attached.');
            }

            /*
             * mayServe, not mayBeTried.
             *
             * The looser rule is correct for a connection test — that is how a
             * credential becomes proven in the first place — and wrong here.
             * Readiness is the question "may this provider be given real work",
             * and a credential nobody has ever successfully used is not an
             * answer of yes. The two rules exist so that testing a credential
             * is possible without production serving being weakened to allow
             * it.
             */
            if (! $credential->mayServe($provider->environment)) {
                return $blocked(BlockerReason::Credentials, sprintf(
                    'The credential is %s in %s.',
                    $credential->state->value,
                    $credential->environment->value,
                ));
            }
        }

        if ($entry->needsEndpoint && ($provider->endpoint === null || trim($provider->endpoint) === '')) {
            return $blocked(BlockerReason::Configuration, 'No endpoint address is configured.');
        }

        if (! $testerAvailable) {
            /*
             * Configuration rather than Dependency, because this is Lynomia's
             * gap and not the provider's: the adapter exists and can do the
             * work, and what is missing is a tester written against the real
             * endpoint. Saying so plainly is better than a provider that sits
             * at "not tested" for ever with no explanation.
             */
            return $blocked(BlockerReason::Configuration, sprintf(
                'No connection tester exists for the %s driver yet, so this cannot be proven.',
                $provider->driver,
            ));
        }

        if (! $provider->connection_state->reached()) {
            return new ReadinessVerdict(
                ReadinessState::ReadyForTest,
                $provider->connection_state->blocker() ?? BlockerReason::Network,
                sprintf('Nothing has answered yet: %s.', $provider->connection_state->value),
            );
        }

        if (! $provider->connection_state->usable()) {
            return $blocked(
                $provider->connection_state->blocker() ?? BlockerReason::Network,
                sprintf('It answered, but not usefully: %s.', $provider->connection_state->value),
            );
        }

        if ($provider->capabilities->isEmpty()) {
            return new ReadinessVerdict(
                ReadinessState::ReadyForTest,
                BlockerReason::Configuration,
                'It answers, but nobody has asked what it can do. Run discovery.',
            );
        }

        return ReadinessVerdict::ready();
    }
}
