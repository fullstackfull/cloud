<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Domains\Domain\Contracts\DomainRegistrarProvider;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegisteredDomain;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\DTOs\TransferStatus;
use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Services\FakeRegistrarGuard;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A registrar that behaves like one, without being one.
 *
 * ---------------------------------------------------------------------------
 * What it is for
 * ---------------------------------------------------------------------------
 *
 * Everything above the provider boundary — the money, the orders, the queue,
 * the state machines, the reconciliation — can be proven end to end against
 * this. That is what makes a domain product `RUNTIME_VERIFIED` on a machine
 * with no registrar account. It is emphatically not `REAL_INFRA_VERIFIED`: no
 * registry has ever answered this platform, and the report says so in those
 * words.
 *
 * ---------------------------------------------------------------------------
 * Markers
 * ---------------------------------------------------------------------------
 *
 * Behaviour is driven by what the name contains, so a test arranges an outcome
 * by choosing a name rather than by reaching inside the double. The two that
 * matter most are the two that cost money:
 *
 *   `-taken`     already registered: a refusal, final, refundable
 *   `-timeout`   no answer: indeterminate, never retried, reconciled
 *
 * and the others make the rest of the product reachable:
 *
 *   `-premium`   available at a per-name price the TLD list does not have
 *   `-unknown`   the availability check itself did not answer
 *   `-refused`   the registry refuses the registration for its own reasons
 *   `-slow`      a transfer that stays pending rather than completing
 *
 * ---------------------------------------------------------------------------
 * Shared state
 * ---------------------------------------------------------------------------
 *
 * With `domains.fake.state_path` set, the portfolio lives in a file and a
 * worker in another process sees what a web request registered. That is what
 * makes the real-Redis proofs possible: without it every process would start
 * with an empty registrar and a worker could not renew what a request bought.
 */
final class FakeDomainRegistrarProvider implements DomainRegistrarProvider
{
    public const string NAME = 'fake';

    public const string TAKEN_MARKER = '-taken';

    public const string TIMEOUT_MARKER = '-timeout';

    /**
     * A registrar that does not answer an availability check.
     *
     * Distinct from TIMEOUT_MARKER, and it has to be: that one means the
     * registrar went quiet during a *purchase*, which a name has to be
     * orderable to reach. One marker for both moments would make the
     * interesting case — a name that searches cleanly and then times out with
     * the customer's money in flight — impossible to rehearse.
     */
    public const string UNREACHABLE_MARKER = '-unreachable';

    public const string PREMIUM_MARKER = '-premium';

    public const string UNKNOWN_MARKER = '-unknown';

    public const string REFUSED_MARKER = '-refused';

    public const string SLOW_TRANSFER_MARKER = '-slow';

    /**
     * A credential-shaped string the refusals quote back, because that is what
     * a real reseller client does when it fails: it prints the request it
     * sent. Nothing may store a provider message without redacting it, and
     * this is what makes that testable rather than assumed.
     */
    private const string RESELLER_KEY = 'fake-reseller-key-0123456789abcdef';

    /** @var array<string, array{expires_at: string, nameservers: list<string>, locked: bool, registered_at: string}> */
    private array $held = [];

    /** @var array<string, array{state: string, expires_at: ?string}> */
    private array $transfers = [];

    private ?string $statePath;

    public function __construct()
    {
        FakeRegistrarGuard::assertNotProduction(self::NAME);

        $path = config('domains.fake.state_path');

        $this->statePath = is_string($path) && $path !== '' ? $path : null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Everything, because the fake exists to make the whole product reachable.
     *
     * A real adapter says no to several of these, and the code paths that
     * handle a no are proven by tests that swap in a provider which does.
     */
    public function redemptionSupport(): RedemptionSupport
    {
        return RedemptionSupport::Supported;
    }

    public function supports(RegistrarCapability $capability): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function supportedTlds(): array
    {
        /** @var list<string> $configured */
        $configured = config('domains.fake.tlds', ['com', 'net', 'org', 'sy', 'com.sy']);

        return $configured;
    }

    /**
     * @param  list<string>  $names
     * @return list<AvailabilityAnswer>
     */
    public function checkAvailability(array $names): array
    {
        $this->read();

        $answers = [];

        foreach ($names as $name) {
            $name = strtolower($name);

            /*
             * A registrar that times out has not said the name is free. The
             * fake throws here rather than answering, because the behaviour
             * worth rehearsing is the caller's: a search must degrade to
             * "we could not answer" and everything that spends money must
             * refuse to proceed. Answering `available` would let both paths
             * pass a test they should fail.
             */
            if (str_contains($name, self::UNREACHABLE_MARKER)) {
                throw DomainRegistrarException::indeterminate(
                    'the fake registrar did not answer an availability check for '.$name,
                );
            }

            $answers[] = match (true) {
                // Asked about a name nobody can answer for.
                str_contains($name, self::UNKNOWN_MARKER) => AvailabilityAnswer::unknown($name),

                // Held by this platform's own account, or by the marker.
                isset($this->held[$name]),
                str_contains($name, self::TAKEN_MARKER) => AvailabilityAnswer::unavailable($name),

                str_contains($name, self::PREMIUM_MARKER) => AvailabilityAnswer::premium(
                    $name,
                    // A hundred times an ordinary name, which is the point:
                    // the substitution attack is only interesting when the
                    // two prices are wildly different.
                    Money::ofMinor(250_000, 'KWD'),
                    'fake-premium-quote-'.substr(md5($name), 0, 12),
                ),

                default => AvailabilityAnswer::available($name),
            };
        }

        return $answers;
    }

    public function register(RegistrationRequest $request): RegisteredDomain
    {
        $this->read();

        $name = strtolower($request->name);

        /*
         * Idempotent on the name, and that is the behaviour worth having in a
         * fake. A redelivered job asking to register a name this account
         * already holds gets the existing registration back rather than a
         * second term — which is exactly what a registrar that honours an
         * idempotency key does, and what the platform's own guard has to work
         * with.
         */
        if (isset($this->held[$name])) {
            return $this->describe($name);
        }

        if (str_contains($name, self::TIMEOUT_MARKER)) {
            /*
             * The dangerous case, modelled honestly: the name IS registered
             * and the caller is told nothing. A platform that retried this
             * would buy a second term; a platform that called it failed would
             * refund a domain the customer owns. Only reconciliation can
             * settle it, and it can only do that because the fake really did
             * write the row.
             */
            $this->held[$name] = $this->newHolding($request);
            $this->write();

            throw DomainRegistrarException::indeterminate(
                'The registrar did not answer within the timeout. Request signed with '.self::RESELLER_KEY,
            );
        }

        if (str_contains($name, self::TAKEN_MARKER)) {
            throw DomainRegistrarException::refused(sprintf('%s is already registered.', $name));
        }

        if (str_contains($name, self::REFUSED_MARKER)) {
            throw DomainRegistrarException::refused(
                'The registry refused this registration. Request signed with '.self::RESELLER_KEY,
            );
        }

        $this->held[$name] = $this->newHolding($request);
        $this->write();

        return $this->describe($name);
    }

    public function renew(string $name, int $termYears): RegisteredDomain
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        if (str_contains($name, self::TIMEOUT_MARKER)) {
            throw DomainRegistrarException::indeterminate('The registrar did not answer the renewal.');
        }

        /*
         * From the expiry date, not from today. A registry that renewed from
         * today would silently shorten every domain renewed early, and the
         * customer would lose the days they paid for twice.
         */
        $current = CarbonImmutable::parse($this->held[$name]['expires_at']);

        $this->held[$name]['expires_at'] = $current->addYears($termYears)->toIso8601String();
        $this->write();

        return $this->describe($name);
    }

    public function inspect(string $name): RegisteredDomain
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        return $this->describe($name);
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(string $name, array $nameservers): void
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        $this->held[$name]['nameservers'] = $nameservers;
        $this->write();
    }

    /**
     * @param  array<string, ContactDetails>  $contacts
     */
    public function setContacts(string $name, array $contacts): void
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        /*
         * Deliberately stores nothing. A fake that kept a registrant's home
         * address in a file on a developer's laptop would be the same leak
         * this module's encryption exists to prevent, in the one place nobody
         * would think to look for it. Accepting the call and forgetting the
         * contents is the honest fake.
         */
        $this->write();
    }

    public function setTransferLock(string $name, bool $locked): void
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        $this->held[$name]['locked'] = $locked;
        $this->write();
    }

    public function authorisationCode(string $name): string
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        // Deterministic, so a test can assert the same code twice, and shaped
        // like a real one so a screen showing it is showing something of the
        // right size.
        return strtoupper(substr(md5('auth:'.$name), 0, 16));
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function startTransfer(string $name, string $authorisationCode, array $nameservers = []): TransferStatus
    {
        $this->read();

        $name = strtolower($name);

        if (isset($this->held[$name])) {
            throw DomainRegistrarException::refused(sprintf('%s is already held here.', $name));
        }

        // Any code will do except an empty one; a registry checks it against
        // the losing registrar and this cannot, but "a code was required" is
        // the property the platform has to get right.
        if (trim($authorisationCode) === '') {
            throw DomainRegistrarException::refused('A transfer authorisation code is required.');
        }

        if (str_contains($name, self::REFUSED_MARKER)) {
            $this->transfers[$name] = ['state' => 'rejected', 'expires_at' => null];
            $this->write();

            return new TransferStatus($name, 'rejected', reason: 'The losing registrar refused the transfer.');
        }

        $this->transfers[$name] = ['state' => 'pending', 'expires_at' => null];
        $this->write();

        return new TransferStatus($name, 'pending');
    }

    public function transferStatus(string $name): TransferStatus
    {
        $this->read();

        $name = strtolower($name);

        if (! isset($this->transfers[$name])) {
            throw DomainRegistrarException::refused(sprintf('No transfer is running for %s.', $name));
        }

        $transfer = $this->transfers[$name];

        if ($transfer['state'] !== 'pending') {
            return new TransferStatus(
                $name,
                $transfer['state'],
                $transfer['expires_at'] === null ? null : CarbonImmutable::parse($transfer['expires_at']),
            );
        }

        /*
         * A marked name stays pending for ever, which is how a test proves the
         * platform copes with the transfer that never finishes — the ordinary
         * case at every registry, where a losing registrar simply lets the
         * five days run out.
         */
        if (str_contains($name, self::SLOW_TRANSFER_MARKER)) {
            return new TransferStatus($name, 'pending');
        }

        // Everything else completes on the second look, so a poller has
        // something to find.
        $expiry = CarbonImmutable::now()->addYear()->startOfSecond();

        $this->held[$name] = [
            'expires_at' => $expiry->toIso8601String(),
            'nameservers' => [],
            'locked' => true,
            'registered_at' => CarbonImmutable::now()->startOfSecond()->toIso8601String(),
        ];

        $this->transfers[$name] = ['state' => 'completed', 'expires_at' => $expiry->toIso8601String()];
        $this->write();

        return new TransferStatus($name, 'completed', $expiry);
    }

    /**
     * Recover a lapsed name.
     *
     * The same two failures the registration models, because they are the
     * two that cost money: a timeout after the request was sent (the name
     * may have been restored and the fee charged, so the caller must not
     * try again), and a refusal that leaves the name where it was.
     */
    public function redeem(string $name): RegisteredDomain
    {
        $this->read();

        $name = strtolower($name);
        $this->assertHeld($name);

        if (str_contains($name, self::TIMEOUT_MARKER)) {
            // Restored at the registry, and the answer lost on the way back.
            $this->held[$name]['expires_at'] = CarbonImmutable::now()->addYear()->startOfSecond()->toIso8601String();
            $this->write();

            throw DomainRegistrarException::indeterminate(sprintf('The registry did not answer the redemption of %s before the deadline.', $name));
        }

        if (str_contains($name, self::REFUSED_MARKER)) {
            throw DomainRegistrarException::refused(sprintf('The registry refused to restore %s: the redemption window has closed.', $name));
        }

        $this->held[$name]['expires_at'] = CarbonImmutable::now()->addYear()->startOfSecond()->toIso8601String();
        $this->write();

        return $this->describe($name);
    }

    /**
     * @return list<string>
     */
    public function heldNames(): array
    {
        $this->read();

        return array_keys($this->held);
    }

    /**
     * Put a name into the registrar without going through a registration.
     *
     * How a test arranges "the registry already holds this" — including the
     * case reconciliation exists for, where the registrar holds a name the
     * platform has no row for.
     *
     * @param  list<string>  $nameservers
     */
    public function seedHolding(
        string $name,
        CarbonImmutable $expiresAt,
        array $nameservers = [],
        bool $locked = true,
    ): void {
        $this->read();

        $this->held[strtolower($name)] = [
            'expires_at' => $expiresAt->toIso8601String(),
            'nameservers' => $nameservers,
            'locked' => $locked,
            'registered_at' => CarbonImmutable::now()->startOfSecond()->toIso8601String(),
        ];

        $this->write();
    }

    /**
     * Take a name away behind the platform's back.
     *
     * The other half of reconciliation: a domain this platform believes it
     * holds and the registrar has never heard of.
     */
    public function forgetHolding(string $name): void
    {
        $this->read();

        unset($this->held[strtolower($name)]);

        $this->write();
    }

    /**
     * @return array{expires_at: string, nameservers: list<string>, locked: bool, registered_at: string}
     */
    private function newHolding(RegistrationRequest $request): array
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return [
            'expires_at' => $now->addYears(max(1, $request->termYears))->toIso8601String(),
            'nameservers' => $request->nameservers,
            // Registries lock a new registration by default, and a platform
            // that assumed otherwise would offer a transfer that fails.
            'locked' => true,
            'registered_at' => $now->toIso8601String(),
        ];
    }

    private function describe(string $name): RegisteredDomain
    {
        $holding = $this->held[$name];

        return new RegisteredDomain(
            name: $name,
            expiresAt: CarbonImmutable::parse($holding['expires_at']),
            providerReference: 'fake-'.substr(md5($name), 0, 16),
            nameservers: $holding['nameservers'],
            transferLocked: $holding['locked'],
            registeredAt: CarbonImmutable::parse($holding['registered_at']),
        );
    }

    private function assertHeld(string $name): void
    {
        if (! isset($this->held[$name])) {
            throw DomainRegistrarException::refused(sprintf('%s is not held by this account.', $name));
        }
    }

    /**
     * The portfolio, from the file when there is one.
     */
    private function read(): void
    {
        if ($this->statePath === null || ! is_file($this->statePath)) {
            return;
        }

        $contents = @file_get_contents($this->statePath);

        if ($contents === false || $contents === '') {
            return;
        }

        /** @var array{held?: array<string, array{expires_at: string, nameservers: list<string>, locked: bool, registered_at: string}>, transfers?: array<string, array{state: string, expires_at: ?string}>}|false $state */
        $state = @unserialize($contents, ['allowed_classes' => false]);

        if (! is_array($state)) {
            return;
        }

        $this->held = $state['held'] ?? [];
        $this->transfers = $state['transfers'] ?? [];
    }

    /**
     * Publishes the portfolio for other processes.
     *
     * Written beside and renamed, so a worker reading while this writes sees
     * either the old portfolio or the new one and never half of either.
     */
    private function write(): void
    {
        if ($this->statePath === null) {
            return;
        }

        $directory = dirname($this->statePath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, recursive: true);
        }

        $temporary = $this->statePath.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, serialize([
            'held' => $this->held,
            'transfers' => $this->transfers,
        ])) === false) {
            return;
        }

        @rename($temporary, $this->statePath);
    }
}
