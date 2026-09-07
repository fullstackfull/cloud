<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Where a console permit lives for the sixty seconds it is worth anything.
 *
 * The cache rather than a table, and that is a deliberate choice rather than a
 * shortcut. A console permit has no history worth keeping, no reporting value
 * and a lifetime shorter than a page load; a table would need a reaper, and a
 * reaper that falls behind turns "short-lived" into "lives until Tuesday".
 * Expiry that the store itself enforces cannot fall behind.
 *
 * Two things are stored, and neither is the token:
 *
 *  - the session record, under the session id, holding a SHA-256 of the token.
 *    A dump of the cache is therefore not a set of usable console permits, in
 *    the same way a dump of the users table is not a set of usable passwords.
 *  - a consumption marker, written with add() — the one cache operation that
 *    is atomic across processes. Two gateway workers redeeming the same
 *    session id at the same instant both call add(); exactly one gets true.
 *    Reading the record and deleting it afterwards would let both through,
 *    and two simultaneous consoles on one permit is one console the customer
 *    did not open.
 *
 * The store must be shared between the API and the console gateway, which
 * means Redis in any real deployment. On an array or per-node file cache the
 * gateway would never see a session the API issued.
 */
final readonly class ConsoleSessionStore
{
    /**
     * Sixty seconds is the window between the API answering and a browser
     * opening a socket. Anything longer is a credential sitting in a tab.
     */
    public const int TTL_SECONDS = 60;

    private const string PREFIX = 'vps:console:session:';

    private const string CONSUMED_PREFIX = 'vps:console:consumed:';

    public function __construct(
        private CacheFactory $cache,
    ) {}

    /**
     * @return ConsoleSession the session as issued, carrying the clear token
     */
    public function issue(string $virtualMachineId, string $customerId, ?string $userId, string $id, string $token): ConsoleSession
    {
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);

        $this->store()->put(self::PREFIX.$id, [
            'virtual_machine_id' => $virtualMachineId,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt->toIso8601String(),
        ], self::TTL_SECONDS);

        return new ConsoleSession(
            id: $id,
            virtualMachineId: $virtualMachineId,
            customerId: $customerId,
            userId: $userId,
            expiresAt: $expiresAt,
            token: $token,
        );
    }

    /**
     * Consume a session, or refuse.
     *
     * Returns null for every way a redemption can be wrong — unknown, expired,
     * already consumed, wrong token — because the caller must answer all four
     * identically. A gateway that could distinguish them would let anybody who
     * can reach it enumerate live sessions.
     */
    public function consume(string $id, string $token): ?ConsoleSession
    {
        /** @var array<string, mixed>|null $record */
        $record = $this->store()->get(self::PREFIX.$id);

        if (! is_array($record)) {
            return null;
        }

        $hash = is_string($record['token_hash'] ?? null) ? $record['token_hash'] : '';

        if (! hash_equals($hash, hash('sha256', $token))) {
            /*
             * Deliberately not consumed. Burning the session on a wrong token
             * would let anyone who can guess a session id — the id travels in
             * a URL, the token does not — destroy a customer's console permit
             * as fast as they can be issued.
             */
            return null;
        }

        // The only atomic step. Whoever wins this writes the marker; everyone
        // else is looking at a session that has already been used.
        if (! $this->store()->add(self::CONSUMED_PREFIX.$id, true, self::TTL_SECONDS)) {
            return null;
        }

        $this->store()->forget(self::PREFIX.$id);

        return new ConsoleSession(
            id: $id,
            virtualMachineId: (string) $record['virtual_machine_id'],
            customerId: (string) $record['customer_id'],
            userId: is_string($record['user_id'] ?? null) ? $record['user_id'] : null,
            expiresAt: CarbonImmutable::parse((string) $record['expires_at']),
        );
    }

    private function store(): Repository
    {
        return $this->cache->store();
    }
}
