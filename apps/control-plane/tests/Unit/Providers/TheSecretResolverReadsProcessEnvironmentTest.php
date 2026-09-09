<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Lynomia\Modules\Providers\Infrastructure\ControllerEnvironmentSecretResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The resolver reads the process environment, not Laravel's .env cache.
 *
 * This exists because the first version used env(), which returns null once
 * config:cache has run — so in production every credential would have read as
 * missing, every provider would have shown blocked, and the control centre
 * would have reported an outage that was entirely its own.
 */
final class TheSecretResolverReadsProcessEnvironmentTest extends TestCase
{
    private const string VARIABLE = 'LYNOMIA_ESTATE_RESOLVER_TEST';

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);
        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_value_behind_a_reference(): void
    {
        putenv(self::VARIABLE.'=a-value');

        $this->assertSame(
            'a-value',
            (new ControllerEnvironmentSecretResolver)->resolve('controller_environment', self::VARIABLE),
        );
    }

    #[Test]
    public function an_unset_variable_is_not_configured_rather_than_an_error(): void
    {
        // Null is an ordinary answer. A credential declared and not yet
        // populated is exactly the state the control centre exists to show.
        $this->assertNull(
            (new ControllerEnvironmentSecretResolver)->resolve('controller_environment', 'LYNOMIA_NEVER_SET_ANYWHERE'),
        );
    }

    #[Test]
    public function an_empty_variable_counts_as_absent(): void
    {
        putenv(self::VARIABLE.'=');

        $this->assertNull(
            (new ControllerEnvironmentSecretResolver)->resolve('controller_environment', self::VARIABLE),
        );
    }

    #[Test]
    public function it_refuses_a_backend_it_does_not_implement(): void
    {
        putenv(self::VARIABLE.'=a-value');

        // A vault-backed credential must not silently fall through to the
        // environment and find something with a coincidentally matching name.
        $this->assertNull(
            (new ControllerEnvironmentSecretResolver)->resolve('vault', self::VARIABLE),
        );
    }
}
