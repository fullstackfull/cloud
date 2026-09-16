<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use SensitiveParameter;

/**
 * The shared skeleton of every tester that reaches a product over HTTPS.
 *
 * ===========================================================================
 * THE ONE RULE, MADE STRUCTURAL
 * ===========================================================================
 *
 * A connection is not healthy because a socket opened.
 *
 * That sentence is easy to agree with and easy to violate, because the
 * violating code looks like success: open the connection, get a 200, write
 * Connected. Phase 30B.0 established that in this project's own sandbox a TLS
 * handshake to a hostname that does not exist completes and verifies, so a
 * tester written that way would report the entire estate as reachable while
 * nothing in the estate had ever been contacted.
 *
 * A comment saying "don't do that" is not protection. So the shape of this
 * class is the protection:
 *
 *   - **This class issues the identity request.** A subclass does not get to
 *     choose whether to ask; it declares the path and interprets the answer.
 *     {@see self::identify()} receives a Response that this class fetched.
 *
 *   - **{@see self::classify()} is unreachable without a matched proof.** The
 *     only method a subclass can return `Connected` or `ConnectedReadOnly`
 *     from is called on exactly one code path, and that path is behind
 *     `$proof->matched`. There is no branch in this file where an unproven
 *     response becomes a usable state, so there is none in any tester.
 *
 *   - **Redirects end the test.** `allow_redirects` is false and a 3xx is
 *     refused by {@see Probe::get()} rather than interpreted, because a
 *     Location header is chosen by whatever answered: following one lets an
 *     impostor hand the credential to a host of its choosing, and lets it
 *     borrow a real product's response to pass the identity check.
 *
 *   - **Certificate verification is never optional here.** There is no
 *     constructor flag, no configuration key and no per-row override. A
 *     cluster with a private certificate authority is onboarded by adding that
 *     authority to the controller's trust store, which is a deployment change
 *     somebody makes once, rather than by switching verification off, which is
 *     a decision that quietly outlives the reason for it.
 *
 * ===========================================================================
 * WHAT NEVER LEAVES THIS FILE
 * ===========================================================================
 *
 * The secret arrives in a TestTarget, is parsed into the driver's native
 * parts, is put into a header, and is gone when the method returns. No step
 * detail, no `detail` string, no capability row and no exception message
 * constructed here contains any part of it, and none contains any part of an
 * upstream response body either — everything a person reads afterwards is
 * built from fixed sentences plus values that passed
 * {@see IdentityProof::token()}.
 *
 * That second half matters as much as the first. An upstream error body is a
 * plausible place to find a session cookie, a token echoed back, an internal
 * address or another customer's hostname, and the `detail` column is
 * persisted, rendered on a screen and carried into the audit trail.
 */
abstract class HttpIdentityTester implements ConnectionTester
{
    /**
     * Long enough for a panel under load, short enough that an operator
     * pressing a button on a screen gets an answer rather than a spinner.
     */
    protected const int TIMEOUT_SECONDS = 15;

    public function __construct(private readonly string $driver) {}

    final public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Nothing, unless a tester can read a real inventory.
     *
     * Empty is the honest default. The fake tester answers a fixed hardware
     * list because its whole purpose is to rehearse the screens; a real tester
     * that invented one would put a serial number nobody can find on a machine
     * nobody has seen.
     *
     * @return array<string, string>
     */
    public function discover(TestTarget $target): array
    {
        return [];
    }

    final public function test(TestTarget $target): ConnectionResult
    {
        $steps = [];

        /*
         * The credential's shape, before anything is dialled.
         *
         * A stored value that is not in this driver's native form cannot be
         * sent, so there is nothing to learn from the network and the honest
         * report is that the credential centre holds something unusable. It is
         * emphatically not AuthFailed: no endpoint has refused anything, and
         * telling an operator that one did would send them to rotate a key
         * that the provider has never seen.
         */
        $credential = $target->hasSecret() ? $this->parseCredential($target) : null;

        if ($credential === null) {
            return ConnectionResult::of(
                ConnectionState::CredentialMalformed,
                [ConnectionStep::failed('credential', $target->hasSecret()
                    ? $this->credentialShape()
                    : 'no credential is configured for this provider.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
            );
        }

        $steps[] = ConnectionStep::passed('credential', 'the stored credential is in the shape this driver sends.');

        $probe = new Probe($this->request($target, $credential));

        try {
            $response = $probe->get($this->identityPath(), $this->identityQuery());
        } catch (RedirectRefused $redirected) {
            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                [...$steps, ConnectionStep::passed('https', self::HANDSHAKE_IS_NOT_EVIDENCE), ConnectionStep::failed('identity', $redirected->getMessage())],
                $this->allOf($target, CapabilityState::Unknown),
                $redirected->getMessage(),
            );
        } catch (ConnectionException $failed) {
            return $this->transportFailure($target, $steps, $failed);
        }

        $steps[] = ConnectionStep::passed('https', self::HANDSHAKE_IS_NOT_EVIDENCE);

        $proof = $this->identify($response);

        if (! $proof->matched) {
            /*
             * The decisive branch. Something answered, over verified TLS, and
             * it did not say anything only this product says — so the platform
             * does not know what it is talking to, and will not guess.
             */
            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                [...$steps, ConnectionStep::failed('identity', $proof->evidence)],
                $this->allOf($target, CapabilityState::Unknown),
                $proof->evidence,
            );
        }

        $steps[] = ConnectionStep::passed('identity', $proof->evidence);

        if ($proof->credentialRejected) {
            return ConnectionResult::of(
                ConnectionState::AuthFailed,
                [...$steps, ConnectionStep::failed('authenticate', 'the product refused the credential.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
                $proof->evidence,
            );
        }

        try {
            $result = $this->classify($probe, $target, $proof, $response);
        } catch (RedirectRefused $redirected) {
            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                [...$steps, ...$probe->steps, ConnectionStep::failed('authorise', $redirected->getMessage())],
                $this->allOf($target, CapabilityState::Unknown),
                $redirected->getMessage(),
            );
        } catch (ConnectionException $failed) {
            /*
             * The product was identified and then stopped answering partway
             * through. NeedsReview rather than NetworkFailed: a test that got
             * further than the network layer and then lost the connection has
             * learned something contradictory, and an operator should see that
             * rather than a flat "unreachable" they will not believe.
             */
            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                [...$steps, ...$probe->steps, ConnectionStep::failed('authorise', 'the product answered the identity request and then stopped answering.')],
                $this->allOf($target, CapabilityState::Unknown),
                'The product was identified and then stopped answering. A person should look at it.',
            );
        }

        return ConnectionResult::of(
            $result->state,
            [...$steps, ...$result->steps],
            $result->capabilities,
            $result->detail,
        );
    }

    /**
     * The sentence that goes on every successful handshake, in the step list
     * an operator reads.
     *
     * It is here, and it is on every test, because the failure this phase
     * exists to prevent is somebody looking at a green "tls" row and
     * concluding the provider is fine.
     */
    private const string HANDSHAKE_IS_NOT_EVIDENCE = 'the endpoint completed a verified TLS handshake and answered. That is not yet evidence that it is the right product.';

    /**
     * The credential parsed into the parts this driver's API takes, or null
     * when the stored value is not in that shape.
     *
     * @return array<string, string>|null
     */
    abstract protected function parseCredential(TestTarget $target): ?array;

    /**
     * What the credential should have looked like, for an operator who has to
     * fix it.
     *
     * A description of the *form*, never an example containing anything that
     * resembles a real value.
     */
    abstract protected function credentialShape(): string;

    /** Where this product answers, built from the target's endpoint. */
    abstract protected function baseUrl(TestTarget $target): string;

    /**
     * The headers that carry the credential.
     *
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    abstract protected function headers(#[SensitiveParameter] array $credential): array;

    /** The read-only path whose answer proves what this product is. */
    abstract protected function identityPath(): string;

    /** @return array<string, string|int> */
    protected function identityQuery(): array
    {
        return [];
    }

    /**
     * Is this response from the product this driver speaks to?
     *
     * The only question this method answers. It does not decide whether the
     * connection is usable, and it cannot: the state comes from
     * {@see self::classify()}, which this class will not call unless the answer
     * here is yes.
     */
    abstract protected function identify(Response $response): IdentityProof;

    /**
     * What this proven product will let us do.
     *
     * Only ever called for a response that {@see self::identify()} matched and
     * that the product did not reject the credential for. That is the whole
     * false-positive protection: a subclass has no other way to reach a state
     * that {@see ConnectionState::usable()} answers true for.
     */
    abstract protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult;

    /**
     * The one place a request to a provider is built.
     *
     * Final, and the only builder in the hierarchy, so a tester that wanted
     * certificate verification off or redirects followed for one call has
     * nowhere to express it. A subclass that needs a second request — a
     * discovery after a test — takes this one.
     *
     * @param  array<string, string>  $credential
     */
    final protected function request(TestTarget $target, #[SensitiveParameter] array $credential): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($target))
            ->withHeaders($this->headers($credential))
            ->withOptions([
                // Not configurable. See the class docblock.
                'verify' => true,
                'allow_redirects' => false,
            ])
            ->timeout(static::TIMEOUT_SECONDS)
            ->acceptJson();
    }

    /**
     * @param  list<ConnectionStep>  $steps
     */
    private function transportFailure(TestTarget $target, array $steps, ConnectionException $failed): ConnectionResult
    {
        /*
         * The exception's own message is matched and then discarded. cURL
         * writes the endpoint, and sometimes a resolved address, into it, and
         * neither belongs in a persisted detail column. What is kept is which
         * of two things happened, said in this file's own words.
         */
        $tls = preg_match('/\b(SSL|TLS|certificate|cert|self.signed|handshake)\b/i', $failed->getMessage()) === 1;

        if ($tls) {
            return ConnectionResult::of(
                ConnectionState::TlsFailed,
                [...$steps, ConnectionStep::failed('https', 'the TLS handshake did not complete, or the certificate did not verify against this controller\'s trust store.')],
                $this->allOf($target, CapabilityState::BlockedNetwork),
                'The endpoint\'s certificate did not verify. Add the issuing authority to the controller\'s trust store rather than disabling verification.',
            );
        }

        /*
         * Unreachable, including a read that ran out of time.
         *
         * A timeout on a write is indeterminate and the Timeout Rule applies,
         * which is why every mutating adapter in this platform treats one that
         * way. This is a read: nothing was asked to change, so nothing can
         * have half-changed, and reporting it as unreachable is both true and
         * the report an operator can act on.
         */
        return ConnectionResult::of(
            ConnectionState::NetworkFailed,
            [...$steps, ConnectionStep::failed('https', 'nothing answered before the deadline.')],
            $this->allOf($target, CapabilityState::BlockedNetwork),
        );
    }

    /**
     * @return array<string, CapabilityState>
     */
    protected function allOf(TestTarget $target, CapabilityState $state): array
    {
        return array_fill_keys($target->probeCapabilities, $state);
    }

    /**
     * Capabilities where the named ones are known and every other one asked
     * about stays Unknown.
     *
     * The default is Unknown on purpose and it is the honest one for most of
     * this platform's capabilities, because most of them are writes. Reading a
     * Proxmox node list proves the platform can read a node list. It proves
     * nothing about whether this token may create a virtual machine, and a
     * connection test may not find that out — a test that created one would
     * not be a test.
     *
     * @param  array<string, CapabilityState>  $known
     * @return array<string, CapabilityState>
     */
    protected function capabilities(TestTarget $target, array $known): array
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
