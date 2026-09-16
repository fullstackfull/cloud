<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Stripe, tested through the SDK the adapter uses, and refused before it is
 * dialled when the key is for the wrong world.
 *
 * ===========================================================================
 * WHY THIS ONE DOES NOT EXTEND HttpIdentityTester
 * ===========================================================================
 *
 * Because {@see StripePaymentProvider}
 * does not speak HTTP to Stripe — it uses the official SDK, which owns its own
 * client, its own retries and its own error hierarchy. A tester that opened its
 * own HTTP connection to `api.stripe.com` would be proving something about a
 * code path the platform never takes. The rule this file obeys is the same
 * rule: identity first, and nothing usable reported without it.
 *
 * ===========================================================================
 * LIVE MODE IS PART OF THE IDENTITY
 * ===========================================================================
 *
 * This is the check worth having and it exists nowhere else in the platform.
 * A Stripe test-mode account and the live account behind it are *different
 * accounts*: the test one has its own balance, its own customers and no money
 * in it. A live key in a staging deployment charges real cards from a system
 * nobody is watching; a test key in production takes orders that are never
 * paid for and reports each one as settled.
 *
 * The credential centre already refuses to resolve a staging reference for a
 * production row — {@see CredentialReference::mayBeTried()}
 * — but that is about which *reference* may be used, and it cannot see which
 * world the value behind it belongs to. A live key recorded in the production
 * variable of a staging deployment passes it.
 *
 * So the key's own declared mode is checked first, offline, and a mismatch is
 * refused **without contacting Stripe at all**: the safest thing to do with a
 * live key that should not be here is not to send it. Stripe's own `livemode`
 * is then read and checked again, because a restricted key's prefix is a
 * claim and the API's answer is the fact.
 *
 * ===========================================================================
 * NOTHING HERE MOVES MONEY
 * ===========================================================================
 *
 * Two reads: the account and the balance. Whether this key may take a payment
 * cannot be established without taking one, so `charge` and `refund` stay
 * Unknown. A zero-amount authorisation would be a charge, and a charge is not
 * a test.
 */
final readonly class StripeConnectionTester implements ConnectionTester
{
    public function __construct(private ?StripeClient $client = null) {}

    public function driver(): string
    {
        return 'stripe';
    }

    /**
     * @return array<string, string>
     */
    public function discover(TestTarget $target): array
    {
        // A payment account has no inventory. Nothing is the honest answer,
        // and the account's identifiers are not facts a screen needs.
        return [];
    }

    public function test(TestTarget $target): ConnectionResult
    {
        if (! $target->hasSecret()) {
            return ConnectionResult::of(
                ConnectionState::CredentialMalformed,
                [ConnectionStep::failed('credential', 'no Stripe secret key is configured for this provider.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
            );
        }

        $key = trim((string) $target->secret);

        if (preg_match('/^(?<kind>sk|rk)_(?<mode>live|test)_[A-Za-z0-9]+$/', $key, $parts) !== 1) {
            return ConnectionResult::of(
                ConnectionState::CredentialMalformed,
                [ConnectionStep::failed('credential', 'the stored value is not a Stripe secret or restricted key. '
                    .'A Stripe secret key begins sk_live_ or sk_test_, and a restricted key begins rk_live_ or '
                    .'rk_test_. A publishable key is not a credential and cannot be used here.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
            );
        }

        $steps = [ConnectionStep::passed('credential', sprintf(
            'the stored value is a Stripe %s key for %s mode.',
            $parts['kind'] === 'rk' ? 'restricted' : 'secret',
            $parts['mode'],
        ))];

        $wantsLive = $target->environment === DeploymentEnvironment::Production;

        if (($parts['mode'] === 'live') !== $wantsLive) {
            return $this->wrongWorld($target, $steps, $parts['mode'], $wantsLive, dialled: false);
        }

        $client = $this->client ?? new StripeClient($key);

        try {
            /*
             * The account behind the key. Retrieved with no identifier, which
             * is the SDK's way of asking "whose key is this" — a read, and the
             * only call that can establish identity without assuming it.
             */
            $account = $client->accounts->retrieve();
        } catch (AuthenticationException) {
            /*
             * Stripe's own exception class, raised only for a credential its
             * API rejected. That is the evidence: the answer came from Stripe
             * — the SDK spoke to api.stripe.com over a verified connection and
             * parsed a Stripe error object — so reporting an authentication
             * failure here is a proven statement rather than an inference from
             * a 401 that could have come from anywhere.
             *
             * Only the exception's class is used. Its message carries the
             * failed request's body, which is one of the commonest ways an API
             * key reaches a log file.
             */
            return ConnectionResult::of(
                ConnectionState::AuthFailed,
                [...$steps, ConnectionStep::passed('identity', 'Stripe answered with its own error type, so the API reached is Stripe.'),
                    ConnectionStep::failed('authenticate', 'Stripe rejected the key. It is wrong, revoked, or rolled.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
                'Stripe rejected the key.',
            );
        } catch (ApiConnectionException) {
            return ConnectionResult::of(
                ConnectionState::NetworkFailed,
                [...$steps, ConnectionStep::failed('https', 'the Stripe API could not be reached from this controller.')],
                $this->allOf($target, CapabilityState::BlockedNetwork),
            );
        } catch (RateLimitException) {
            return ConnectionResult::of(
                ConnectionState::ProviderUnavailable,
                [...$steps, ConnectionStep::failed('authenticate', 'Stripe is rate limiting this controller.')],
                $this->allOf($target, CapabilityState::Unknown),
                'Stripe is rate limiting this controller. Nothing is wrong with the credential.',
            );
        } catch (PermissionException) {
            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                [...$steps, ConnectionStep::passed('identity', 'Stripe answered with its own error type.'),
                    ConnectionStep::failed('authorise', 'the key authenticated and is not permitted to read its own account.')],
                $this->allOf($target, CapabilityState::Unknown),
                'The key is accepted and is restricted away from reading the account. A restricted key needs read '
                .'access to the account and the balance for this platform to establish anything about it.',
            );
        } catch (ApiErrorException) {
            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                [...$steps, ConnectionStep::failed('identity', 'Stripe answered with an error this platform does not classify.')],
                $this->allOf($target, CapabilityState::Unknown),
                'Stripe answered with an error this platform does not classify. A person should look at it.',
            );
        }

        $id = IdentityProof::token($account->id ?? null);

        if ($account->object !== 'account' || $id === null || ! str_starts_with($id, 'acct_')) {
            /*
             * Reachable when something other than Stripe is answering for
             * api.stripe.com — a TLS-inspecting corporate proxy, a recorded
             * fixture pointed at the wrong cassette, a test double wired in by
             * mistake. The SDK would parse such an answer happily; this will
             * not accept it.
             */
            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                [...$steps, ConnectionStep::failed('identity', 'what answered returned an object that is not a Stripe account.')],
                $this->allOf($target, CapabilityState::Unknown),
                'Whatever answered for the Stripe API did not return a Stripe account object, so the platform will '
                .'not treat this as a payment provider.',
            );
        }

        $steps[] = ConnectionStep::passed('identity', 'Stripe returned an account object for this key.');
        $steps[] = ConnectionStep::passed('authenticate', 'Stripe accepted the key.');

        try {
            $balance = $client->balance->retrieve();
        } catch (ApiErrorException) {
            /*
             * The account read and the balance did not. Connected, with
             * nothing claimed: the key is real and this platform has not
             * established which world it is in, and Unknown is what that
             * means.
             */
            return ConnectionResult::of(
                ConnectionState::Connected,
                [...$steps, ConnectionStep::passed('capabilities', 'the balance could not be read, so this account\'s mode and currencies are unknown.')],
                $this->allOf($target, CapabilityState::Unknown),
            );
        }

        if ((bool) $balance->livemode !== $wantsLive) {
            return $this->wrongWorld($target, $steps, $balance->livemode ? 'live' : 'test', $wantsLive, dialled: true);
        }

        $steps[] = ConnectionStep::passed('authorise', sprintf(
            'Stripe confirms the key operates in %s mode, which is what this %s provider row is for.',
            $wantsLive ? 'live' : 'test',
            $target->environment->value,
        ));

        $chargesEnabled = (bool) ($account->charges_enabled ?? false);

        if (! $chargesEnabled) {
            $steps[] = ConnectionStep::failed('capabilities', 'Stripe reports that this account may not take charges yet.');

            return ConnectionResult::of(
                ConnectionState::ConnectedReadOnly,
                $steps,
                $this->capabilities($target, [
                    'charge' => CapabilityState::Unsupported,
                    'refund' => CapabilityState::Unsupported,
                ]),
                'The key is valid and Stripe has not enabled charges on this account. Onboarding is incomplete at '
                .'Stripe; no change here will make a payment succeed.',
            );
        }

        /*
         * `charge` and `refund` stay Unknown on a healthy account, and that is
         * not timidity. Stripe enabling charges on an account says the account
         * may take payments; it does not say this key may, that this currency
         * is enabled on it, or that the card this customer will use will be
         * accepted. The only way to establish those is to take a payment, and
         * then it is not a test.
         */
        $steps[] = ConnectionStep::passed('capabilities', 'Stripe reports this account may take charges. Whether a '
            .'particular payment will succeed is established by making one, which a connection test does not do.');

        return ConnectionResult::of(
            ConnectionState::Connected,
            $steps,
            $this->capabilities($target, ['currencies' => CapabilityState::Supported]),
        );
    }

    /**
     * A key for the other Stripe.
     *
     * @param  list<ConnectionStep>  $steps
     */
    private function wrongWorld(TestTarget $target, array $steps, string $mode, bool $wantsLive, bool $dialled): ConnectionResult
    {
        $detail = sprintf(
            'The key operates in Stripe %s mode and this provider row is a %s row. A Stripe test account and the '
            .'live account behind it are different accounts with different balances and different customers, so this '
            .'row would %s. %s',
            $mode,
            $target->environment->value,
            $wantsLive
                ? 'take orders that are never paid for and report every one of them as settled'
                : 'charge real cards from an environment nobody is watching',
            $dialled
                ? 'Stripe itself confirmed the mode.'
                : 'The key was not sent anywhere: the safest thing to do with a key that should not be here is not to use it.',
        );

        return ConnectionResult::of(
            ConnectionState::IdentityMismatch,
            [...$steps, ConnectionStep::failed('identity', sprintf(
                'the credential is for Stripe %s mode and this row needs %s mode.',
                $mode,
                $wantsLive ? 'live' : 'test',
            ))],
            $this->allOf($target, CapabilityState::Unknown),
            $detail,
        );
    }

    /**
     * @return array<string, CapabilityState>
     */
    private function allOf(TestTarget $target, CapabilityState $state): array
    {
        return array_fill_keys($target->probeCapabilities, $state);
    }

    /**
     * @param  array<string, CapabilityState>  $known
     * @return array<string, CapabilityState>
     */
    private function capabilities(TestTarget $target, array $known): array
    {
        $states = $this->allOf($target, CapabilityState::Unknown);

        foreach ($known as $capability => $state) {
            if (array_key_exists($capability, $states)) {
                $states[$capability] = $state;
            }
        }

        return $states;
    }
}
