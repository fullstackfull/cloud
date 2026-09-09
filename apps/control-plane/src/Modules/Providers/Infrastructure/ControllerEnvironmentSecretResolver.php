<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure;

use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;

/**
 * Secrets from the deployment controller's own environment.
 *
 * This is the backend Phase 30B settled on, and the reason it is first rather
 * than a vault: the controller is already the only thing that crosses into the
 * management network, it already holds the credentials the playbooks need, and
 * adding a second secret store would mean two places to rotate and two places
 * to leak from.
 *
 * The brief for this phase says not to build a secrets manager, and this is
 * not one. It reads a named variable and returns it.
 */
final readonly class ControllerEnvironmentSecretResolver implements SecretResolver
{
    public function resolve(string $backend, string $reference): ?string
    {
        if ($backend !== 'controller_environment') {
            return null;
        }

        /*
         * getenv, deliberately, and not Laravel's env() helper.
         *
         * env() reads values Laravel loaded from a .env file, and a production
         * deployment runs config:cache — after which Laravel does not load
         * .env at all and env() returns null for everything. A secret resolver
         * that quietly returns null in production is worse than one that
         * fails: every credential would show as missing, every provider would
         * show as blocked, and the control centre would confidently report an
         * outage that is entirely its own.
         *
         * These variables are not .env values. They are process environment,
         * set on the deployment controller by whatever supervises it, and PHP
         * populates them at startup regardless of what Laravel has cached.
         *
         * The reference is an exact variable name from a credential row an
         * operator created. It is never interpolated and never prefixed with
         * anything a caller supplied — a reference is a key, not a pattern.
         */
        $value = getenv($reference);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
