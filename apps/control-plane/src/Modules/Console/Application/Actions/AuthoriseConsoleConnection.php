<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Application\Actions;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleConnectionRefusedException;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleUpstreamUnavailableException;
use Lynomia\Modules\Console\Domain\ValueObjects\AuthorisedConsoleConnection;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Vps\Application\Actions\RedeemConsoleSession;
use Lynomia\Modules\Vps\Domain\Exceptions\ConsoleSessionInvalidException;

/**
 * The gateway's one security decision, made in one place.
 *
 * Everything a connection has to prove happens here and nowhere else, so that
 * the socket loop below it is transport and nothing more. The order is chosen
 * so the cheapest refusals happen first and the atomic one happens exactly
 * once:
 *
 *  1. **Rate limit**, keyed on the peer. A gateway that redeemed as fast as it
 *     was asked would let a stolen session id be brute-forced for its token at
 *     line rate — and the token is 32 random bytes, so the limit is not what
 *     makes guessing hopeless, it is what makes the attempt visible and cheap
 *     to stop.
 *  2. **Redeem, atomically.** One winner per permit, decided by the store's
 *     `add()`. Everything after this point has already spent the permit, which
 *     is the correct order: a permit that was presented is a permit that is
 *     gone, whether or not the rest of the checks pass. The alternative —
 *     validate first, consume last — leaves a window in which two connections
 *     both pass validation.
 *  3. **Bind to the customer.** The permit names the customer it was issued
 *     to; a connection claiming a different one is refused even though the
 *     token is valid.
 *  4. **Bind to the machine.** The client says which machine it believes it is
 *     opening. A permit for VPS A presented for VPS B is refused — the check
 *     that stops a customer with two machines swapping one permit for a
 *     console on the other.
 *  5. **Re-check the service.** Permits live for a minute, and a suspension
 *     can land inside it. A console is root access, so it is refused for a
 *     service that is no longer active even when the permit was legitimately
 *     issued a moment ago.
 *
 * Every refusal answers the caller identically — see
 * {@see ConsoleConnectionRefusedException} — and is recorded with its real
 * reason, which is the only place the difference exists.
 */
final readonly class AuthoriseConsoleConnection
{
    /**
     * Redemption attempts allowed from one peer per minute.
     *
     * Generous for a person — a browser opens one socket per console — and
     * miserly for anything walking session ids.
     */
    private const int ATTEMPTS_PER_MINUTE = 30;

    public function __construct(
        private RedeemConsoleSession $redeem,
        private ConsoleUpstreamResolver $upstreams,
        private RateLimiter $limiter,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @param  string  $peer  The connecting address, for rate limiting and the audit trail.
     *
     * @throws ConsoleConnectionRefusedException
     */
    public function execute(
        string $sessionId,
        string $token,
        string $claimedMachineId,
        string $peer,
    ): AuthorisedConsoleConnection {
        $key = 'console-gateway:'.$peer;

        if ($this->limiter->tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            $this->refuse('rate_limited', $peer, null, $claimedMachineId);
        }

        $this->limiter->hit($key, decaySeconds: 60);

        try {
            // Spends the permit. Nothing below may put it back.
            $session = $this->redeem->execute($sessionId, $token);
        } catch (ConsoleSessionInvalidException) {
            /*
             * Unknown, expired, already spent, or the wrong token. The store
             * deliberately cannot tell them apart, and neither can this: a
             * gateway that could would let anyone who can reach it enumerate
             * live sessions.
             */
            $this->refuse('invalid_permit', $peer, null, $claimedMachineId);
        }

        $machine = VirtualMachine::query()->find($session->virtualMachineId);

        if ($machine === null) {
            $this->refuse('machine_gone', $peer, $session->customerId, $claimedMachineId);
        }

        if ($claimedMachineId !== '' && ! hash_equals($session->virtualMachineId, $claimedMachineId)) {
            /*
             * A valid permit presented for a different machine. This is the
             * check that stops a customer who owns two servers from opening a
             * console on the second with a permit minted for the first — and
             * the one a gateway that trusted the URL would fail.
             */
            $this->refuse('machine_mismatch', $peer, $session->customerId, $claimedMachineId);
        }

        $service = $machine->service()->first();

        if ($service === null || $service->status !== ServiceStatus::Active) {
            /*
             * The permit was legitimate a minute ago and the service has been
             * cut off since. A console is root access on a machine the
             * platform has decided to suspend, so the answer is no — this is
             * the same refusal POST /power gives, arriving one layer later.
             */
            $this->refuse('service_not_active', $peer, $session->customerId, $claimedMachineId);
        }

        try {
            $upstream = $this->upstreams->resolve($session);
        } catch (ConsoleUpstreamUnavailableException $e) {
            /*
             * The permit was good and the hypervisor would not say where the
             * console is. Logged as an operational failure rather than a
             * refusal, because it is the platform's problem and not the
             * customer's — but the connection still ends here.
             */
            Log::warning('A console upstream could not be resolved.', [
                'session_id' => $sessionId,
                'virtual_machine_id' => $session->virtualMachineId,
                'reason' => $e->getMessage(),
            ]);

            $this->refuse('upstream_unavailable', $peer, $session->customerId, $claimedMachineId);
        }

        $this->audit->execute(
            action: AuditAction::ConsolePermitRedeemed,
            subject: $machine,
            customerId: $session->customerId,
            context: [
                'session_id' => $session->id,
                'peer' => $peer,
                'user_id' => $session->userId,
                ...$upstream->forLogging(),
            ],
        );

        return new AuthorisedConsoleConnection($session, $upstream, $machine->getKey());
    }

    /**
     * Record why, tell the caller nothing.
     *
     * @throws ConsoleConnectionRefusedException
     */
    private function refuse(string $reason, string $peer, ?string $customerId, string $claimedMachineId): never
    {
        $this->audit->execute(
            action: AuditAction::ConsolePermitRefused,
            customerId: $customerId,
            context: [
                'reason' => $reason,
                'peer' => $peer,
                // What the caller claimed, not what the platform believes.
                // Recorded so a pattern of wrong machine ids from one peer is
                // visible as the enumeration attempt it is.
                'claimed_virtual_machine_id' => $claimedMachineId,
            ],
        );

        throw ConsoleConnectionRefusedException::because($reason);
    }
}
