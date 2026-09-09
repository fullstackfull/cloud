<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\DTOs;

use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use SensitiveParameter;

/**
 * Everything a tester is allowed to know about what it is testing.
 *
 * The secret arrives here already resolved, and this object is never
 * persisted, logged or serialised — `__debugInfo` and `__toString` are
 * overridden so that a var_dump in a debugging session, or an exception
 * rendering its arguments, cannot spill it.
 *
 * Capabilities to probe are passed in rather than decided by the tester,
 * because what the platform needs to know differs by category and a tester
 * that decides for itself will drift from the category's list.
 */
final readonly class TestTarget
{
    /**
     * @param  list<string>  $probeCapabilities
     */
    public function __construct(
        public string $driver,
        public DeploymentEnvironment $environment,
        public ?string $endpoint,
        #[SensitiveParameter]
        public ?string $secret,
        public array $probeCapabilities = [],
        public ?string $identity = null,
    ) {}

    /**
     * Deliberately redacted.
     *
     * A stack trace that renders its arguments is one of the commonest ways a
     * credential reaches a log file, and this object exists precisely at the
     * boundary where a credential is in memory.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'driver' => $this->driver,
            'environment' => $this->environment->value,
            'endpoint' => $this->endpoint,
            'secret' => $this->secret === null ? null : '[redacted]',
            'probeCapabilities' => $this->probeCapabilities,
            'identity' => $this->identity,
        ];
    }

    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }
}
