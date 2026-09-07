<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * How a caller's idempotency key becomes the provisioning engine's.
 *
 * Three properties are required of the result and none of them is optional:
 *
 *  - **Scoped to the machine and the operation.** One client key reused for
 *    two different operations on one machine is two intents, and they must not
 *    converge on one job. Reused for the same operation on the same machine it
 *    is one intent, and it must.
 *  - **Scoped to the machine, again.** The engine's key column is unique
 *    platform-wide. Without the machine id, one customer choosing the key
 *    "reinstall-1" would silently claim another customer's job — the second
 *    caller would get back a job for a machine they do not own, and their own
 *    reinstall would never run.
 *  - **Bounded.** ProvisioningJobRequest refuses a key over 128 characters
 *    rather than truncating one, because two intents that agree in their first
 *    128 characters would become one job. Hashing the caller's half fixes the
 *    length at 64 whatever they send, so a legal client key can never be
 *    rejected for arithmetic the client cannot see.
 */
final class DedicatedIdempotencyKey
{
    public static function for(DedicatedServer $server, string $operation, string $clientKey): string
    {
        return sprintf(
            'dedicated:%s:%s:%s',
            $server->getKey(),
            $operation,
            hash('sha256', $clientKey),
        );
    }
}
