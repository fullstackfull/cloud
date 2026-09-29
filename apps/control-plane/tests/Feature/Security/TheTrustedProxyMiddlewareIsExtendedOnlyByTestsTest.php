<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Lynomia\Http\Middleware\TrustProxies;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Tests\Support\Queue\QueuedClasses;
use Tests\TestCase;

/**
 * The trusted-proxy middleware is not `final`, because the suite needs to
 * simulate a PHP built without IPv6 by replacing one of its methods. That
 * makes every method a subclass could replace a place where the refusals can
 * be undone, so this pins two things the middleware's docblock would
 * otherwise have to keep as a list in prose.
 *
 * The boundary: nothing in the application extends it. The roots searched are
 * read from `composer.json`'s `autoload.psr-4` — the file that decides where
 * a class can be loaded from at all — rather than typed here. `autoload-dev`
 * is excluded, because `Tests\` is exactly where the simulation lives.
 *
 * The size: which methods a subclass could replace. A method being declared
 * by this class decides what a subclass INHERITS, not what it may REPLACE;
 * the four inherited from Illuminate's middleware are as replaceable as the
 * four declared here. A new method on the class, or one made final, changes
 * this list and is a decision somebody has to make on purpose.
 */
final class TheTrustedProxyMiddlewareIsExtendedOnlyByTestsTest extends TestCase
{
    /**
     * The replaceable methods every request passes through.
     */
    private const array ON_THE_REQUEST_PATH = [
        'covers',
        'getTrustedHeaderNames',
        'handle',
        'headers',
        'matchesEveryCaller',
        'proxies',
        'setTrustedProxyIpAddresses',
        'setTrustedProxyIpAddressesToSpecificIps',
    ];

    /**
     * Replaceable, inherited, and reached by nothing: its body is
     * `setTrustedProxies(['0.0.0.0/0', '::/0'])`, which is precisely what the
     * middleware exists to refuse. The inherited dispatcher calls it only for
     * a list of `'*'` or `'**'`, or for a null list on a guessed hosting
     * platform, and the middleware never produces either.
     * {@see NoConfigurationTrustsEveryCallerTest} pins that behaviourally.
     */
    private const array REPLACEABLE_BUT_NEVER_REACHED = [
        'setTrustedProxyIpAddressesToTheCallingIp',
    ];

    #[Test]
    public function the_methods_a_subclass_could_replace_are_exactly_these(): void
    {
        $replaceable = [];

        foreach ((new ReflectionClass(TrustProxies::class))->getMethods() as $method) {
            // Static methods are the framework's process-wide switches
            // (`at`, `withHeaders`, `flushState`). The middleware reads
            // neither piece of state they write; both halves are pinned in
            // NoConfigurationTrustsEveryCallerTest.
            if ($method->isPrivate() || $method->isFinal() || $method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $replaceable[] = $method->getName();
        }

        sort($replaceable);

        $expected = [...self::ON_THE_REQUEST_PATH, ...self::REPLACEABLE_BUT_NEVER_REACHED];
        sort($expected);

        $this->assertSame($expected, $replaceable);
        $this->assertCount(8, self::ON_THE_REQUEST_PATH);
    }

    #[Test]
    public function the_class_declares_exactly_four_replaceable_methods_of_its_own(): void
    {
        $declared = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(TrustProxies::class))->getMethods(),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === TrustProxies::class
                    && ! $method->isPrivate(),
            ),
        ));

        sort($declared);

        $this->assertSame(['covers', 'headers', 'matchesEveryCaller', 'proxies'], $declared);
    }

    #[Test]
    public function nothing_in_the_application_extends_it(): void
    {
        $subclasses = [];
        $searched = [];

        foreach (QueuedClasses::roots() as $prefix => $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $prefix.str_replace('/', '\\', substr($file->getPathname(), strlen($directory) + 1, -4));

                if (! class_exists($class)) {
                    continue;
                }

                $searched[] = $class;

                if (is_subclass_of($class, TrustProxies::class)) {
                    $subclasses[] = $class;
                }
            }
        }

        // The search reached the application — the middleware itself among
        // it — or its empty answer would be empty for the wrong reason.
        $this->assertGreaterThan(100, count($searched));
        $this->assertContains(TrustProxies::class, $searched);

        $this->assertSame([], $subclasses, 'A production class extends the trusted-proxy middleware and can replace the methods its refusals live in.');
    }

    /**
     * And the application runs this middleware, in the framework's place —
     * otherwise every other test of it is about a class nothing executes.
     */
    #[Test]
    public function the_kernel_runs_this_middleware_in_place_of_the_frameworks(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);

        $global = $kernel->getGlobalMiddleware();

        $this->assertContains(TrustProxies::class, $global);
        $this->assertNotContains(FrameworkTrustProxies::class, $global);
    }
}
