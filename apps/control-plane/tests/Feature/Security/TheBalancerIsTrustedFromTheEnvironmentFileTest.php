<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The balancer named in `.env` has to be trusted by the process that serves the
 * request, which is a different process from the one this suite runs in.
 *
 * `bootstrap/app.php` used to read `env('TRUSTED_PROXIES')` inside
 * `withMiddleware()`. That closure runs when the HTTP kernel is RESOLVED —
 * `Application::handleRequest()` makes the kernel first and only then calls
 * `handle()`, whose bootstrappers load `.env` — so the read saw the bare
 * process environment. php-fpm does not export `.env` into it, and after
 * `config:cache` the file is never loaded at all. The list came back empty,
 * nothing was trusted, and every caller behind the balancer shared the
 * balancer's address: one bucket for every IP-keyed limiter in the platform.
 *
 * No in-process test can see that, which is why none did. This suite's
 * application is bootstrapped by the console kernel before the first request,
 * so by the time a test resolves the HTTP kernel the environment is already
 * loaded and the early read happens to find the variable. Each test here
 * therefore serves one request in a fresh PHP process, through
 * `Application::handleRequest()` exactly as `public/index.php` does, with
 * `TRUSTED_PROXIES` removed from that process's environment — so the only
 * place the balancer is named is where production names it.
 */
final class TheBalancerIsTrustedFromTheEnvironmentFileTest extends TestCase
{
    private const string BALANCER = '10.0.0.5';

    private const string CUSTOMER = '203.0.113.7';

    /**
     * What `public/index.php` does, plus one line to report the answer.
     *
     * The request is handed to `handleRequest()` rather than to a kernel the
     * script resolves itself, because the order inside that method — make the
     * kernel, then handle — is the order the defect lived in.
     */
    private const string SERVE_ONE_REQUEST = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $base, $environment, $balancer, $customer] = $argv;

        require $base.'/vendor/autoload.php';

        $app = require $base.'/bootstrap/app.php';
        $app->useEnvironmentPath($environment);

        $request = Illuminate\Http\Request::create('/up', 'GET', server: [
            'REMOTE_ADDR' => $balancer,
            'HTTP_X_FORWARDED_FOR' => $customer,
        ]);

        ob_start();
        $app->handleRequest($request);
        ob_end_clean();

        echo json_encode(['ip' => $request->ip()]);
        PHP;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/lynomia-trusted-proxies-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/serve.php', self::SERVE_ONE_REQUEST);
    }

    protected function tearDown(): void
    {
        foreach (['serve.php', '.env', 'config.php'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_balancer_named_only_in_the_environment_file_is_trusted_by_the_request_it_serves(): void
    {
        file_put_contents($this->directory.'/.env', 'TRUSTED_PROXIES='.self::BALANCER."\n");

        $this->assertSame(
            self::CUSTOMER,
            $this->serveOneRequest(['TRUSTED_PROXIES' => false]),
            'A balancer named in .env was not trusted by the request it served, so the customer was keyed on the balancer\'s address.',
        );
    }

    /**
     * The production shape: `config:cache` runs at deploy time, where the
     * variable is present, and the process serving requests never sees the
     * variable or the file again.
     */
    #[Test]
    public function a_balancer_named_when_the_configuration_was_cached_is_trusted_by_a_process_that_never_saw_the_variable(): void
    {
        $cache = $this->directory.'/config.php';

        $cached = new Process(
            [PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cache, 'TRUSTED_PROXIES' => self::BALANCER],
            null,
            120,
        );
        $cached->run();

        $this->assertTrue($cached->isSuccessful(), 'config:cache failed: '.$cached->getErrorOutput().$cached->getOutput());
        $this->assertFileExists($cache);

        $this->assertSame(
            self::CUSTOMER,
            $this->serveOneRequest(['APP_CONFIG_CACHE' => $cache, 'TRUSTED_PROXIES' => false]),
            'A balancer named when the configuration was cached was not trusted by a process serving from that cache.',
        );
    }

    /**
     * The control that makes the two tests above mean something: with no
     * balancer named anywhere, the same request is keyed on the address it
     * arrived from. A harness that always reported the forwarded address
     * would pass both tests above and fail this one.
     */
    #[Test]
    public function with_no_balancer_named_anywhere_the_request_is_keyed_on_the_address_it_arrived_from(): void
    {
        $this->assertSame(self::BALANCER, $this->serveOneRequest(['TRUSTED_PROXIES' => false]));
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function serveOneRequest(array $environment): string
    {
        $process = new Process(
            [PHP_BINARY, $this->directory.'/serve.php', base_path(), $this->directory, self::BALANCER, self::CUSTOMER],
            base_path(),
            $environment,
            null,
            120,
        );
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'The request process failed: '.$process->getErrorOutput().$process->getOutput());

        $served = json_decode($process->getOutput(), true);

        $this->assertIsArray($served, 'The request process printed no answer: '.$process->getOutput());
        $this->assertIsString($served['ip'] ?? null);

        return $served['ip'];
    }
}
