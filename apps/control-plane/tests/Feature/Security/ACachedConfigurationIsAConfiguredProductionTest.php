<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A cached configuration is a configured deployment, to the provider guard as
 * much as to the environment settling step (OB-3, re-audit of round three,
 * F-16 class).
 *
 * The provider guard stands down when "production" is only Laravel's default
 * for an absent value: no APP_ENV, no environment file. The settling step
 * (SettleTheApplicationEnvironment::nothingWasConfigured) also asks whether
 * the configuration is cached, because a production host that ran
 * `config:cache` loads no environment file at all. The guard did not ask. So
 * a host that cached a production configuration naming fake providers and
 * then ran without its environment file booted as production with every
 * boot-time check — fake providers, unknown drivers, a session store the
 * account-security surface cannot enumerate — stood down. Measured at 88c4dd8.
 *
 * Both halves run in child processes: the only way to reach the real boot, and
 * the only way to take APP_ENV out of a process without taking it out of this
 * one. The cached file lives in a temporary directory named by
 * APP_CONFIG_CACHE in the child alone; the checkout's .env and
 * bootstrap/cache are not touched, and the directory is removed afterwards.
 */
final class ACachedConfigurationIsAConfiguredProductionTest extends TestCase
{
    private const string REFUSAL = 'Refusing to run in production with fake providers configured for';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/r4-cached-config-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/env', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/config.php');
        @rmdir($this->directory.'/env');
        @rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function a_cached_production_configuration_with_fake_providers_is_refused_at_boot(): void
    {
        $write = $this->child('write');
        $this->assertTrue($write->isSuccessful(), 'Writing the cached configuration failed: '.$write->getErrorOutput().$write->getOutput());

        /** @var array{app: array{env: string}, billing: array{providers: array<string, string>}} $cached */
        $cached = require $this->directory.'/config.php';

        // Preconditions: what `config:cache` on an unconfigured machine
        // produces — production by default, fake providers by default.
        $this->assertSame('production', $cached['app']['env']);
        $this->assertContains('fake', array_map('strtolower', $cached['billing']['providers']));

        $boot = $this->child('boot');

        /** @var array{refused?: string, env?: string} $outcome */
        $outcome = json_decode(trim($boot->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey(
            'refused',
            $outcome,
            'Booted as '.trim($boot->getOutput()).' from a cached production configuration naming fake providers: the guard stood down.',
        );
        $this->assertStringContainsString(self::REFUSAL, $outcome['refused']);
        $this->assertStringContainsString('read from the cache', $outcome['refused'], 'The refusal points at the wrong cause.');
        $this->assertSame(1, $boot->getExitCode());
    }

    /**
     * The positive control: with no cache as well, nothing configured this
     * machine, "production" is only the default, and the guard still stands
     * down, as it must for package discovery on a fresh clone.
     */
    #[Test]
    public function with_nothing_configured_at_all_the_guard_still_stands_down(): void
    {
        $boot = $this->child('boot');

        $this->assertTrue($boot->isSuccessful(), $boot->getOutput().$boot->getErrorOutput());
        $this->assertSame(['env' => 'production', 'cached' => false], json_decode(trim($boot->getOutput()), true));
    }

    private function child(string $mode): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/boot_on_a_machine_with_no_environment_file.php', $this->directory.'/env', $mode],
            base_path(),
            // false removes the variable from the child; this process keeps it.
            ['APP_ENV' => false, 'APP_CONFIG_CACHE' => $this->directory.'/config.php'],
            null,
            120,
        );
        $process->run();

        return $process;
    }
}
