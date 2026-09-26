<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;
use Tests\Support\RedisIndexForThisRun;
use Tests\Support\TestDatabaseGuard;

/**
 * An exported variable cannot change what the suite is testing against, and
 * can still choose where the suite keeps its disposable state.
 *
 * ---------------------------------------------------------------------------
 * The defect
 * ---------------------------------------------------------------------------
 *
 * Every `<env>` in `phpunit.xml` used to be a default rather than a pin: an
 * exported `CACHE_STORE`, `QUEUE_CONNECTION`, `COMPUTE_PROVIDER` or
 * `DB_CONNECTION` replaced it without a word. `DB_CONNECTION=sqlite` turned
 * two security files from three passes each into three errors each, and
 * `CACHE_STORE=redis` quietly moved every rate limiter onto a shared server.
 *
 * ---------------------------------------------------------------------------
 * Why both attributes, measured rather than remembered
 * ---------------------------------------------------------------------------
 *
 * PHPUnit's `<env>` writes `putenv()` and `$_ENV` and never `$_SERVER`;
 * `force="true"` only decides whether it overwrites those two. `<server>`
 * writes `$_SERVER` and nothing else, unconditionally. Laravel's `env()` reads
 * through phpdotenv, whose first adapter is `$_SERVER`, and this PHP's
 * `variables_order` puts an exported variable in `$_SERVER`. So:
 *
 *  - `force` alone: `env()` still returns the exported value.
 *  - `<server>` alone: `env()` returns the pin, but `getenv()` keeps the
 *    exported value, and a subprocess (`symfony/process` builds its child
 *    environment from `$_ENV` and `getenv()`) inherits the exported value.
 *  - both: every reader, and every child process, sees the pin.
 *
 * The last two tests below measure this through PHPUnit's own handler in a
 * clean child process, so if any leg of it stops being true on some machine
 * they fail there rather than being believed.
 *
 * ---------------------------------------------------------------------------
 * The rule that decides which pins yield
 * ---------------------------------------------------------------------------
 *
 * A pin **yields** when it says only *where* the suite's disposable state
 * lives — the database address and the Redis index — because choosing that per
 * run is how concurrent runs on one machine stay apart, and what arrives is
 * checked by a guard rather than trusted: {@see TestDatabaseGuard}
 * refuses a database not named as a test database before anything is dropped,
 * and {@see RedisIndexForThisRun} refuses an index it cannot
 * read. Every other pin says *what* the suite is testing against — drivers,
 * providers, environment, currency, published documents — and an exported
 * value there changes what a green result means, so it must not yield.
 *
 * A pin that must not yield is `<env force="true">` **and** a `<server>` with
 * the same value. A pin that yields carries neither. A name added to
 * `phpunit.xml` in neither list fails the first test, so the rule is applied
 * to it rather than skipped.
 *
 * `<server>` on a yielding name is the edit that would silently put every
 * concurrent run on one database and one Redis index; the fourth test refuses
 * it for every name, including ones nobody has thought of yet.
 */
final class ThePhpunitPinsHoldAgainstAnExportedVariableTest extends TestCase
{
    /**
     * Pins that say what the suite tests against.
     *
     * @var list<string>
     */
    private const array MUST_NOT_YIELD = [
        'APP_ENV',
        'APP_MAINTENANCE_DRIVER',
        'BCRYPT_ROUNDS',
        'BROADCAST_CONNECTION',
        'CACHE_STORE',
        'DB_CONNECTION',
        'APP_URL',
        'FRONTEND_URL',
        'SANCTUM_STATEFUL_DOMAINS',
        'SESSION_DOMAIN',
        'MAIL_MAILER',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
        'COMPUTE_PROVIDER',
        'DEDICATED_PROVIDER',
        'HOSTING_PROVIDER',
        'DNS_PROVIDER',
        'PAYMENT_PROVIDER',
        'BACKUP_PROVIDER',
        'BILLING_DEFAULT_CURRENCY',
        'LEGAL_TERMS_URL',
        'LEGAL_TERMS_VERSION',
        'LEGAL_AUP_URL',
        'LEGAL_AUP_VERSION',
    ];

    /**
     * Pins that say only where the disposable state lives.
     *
     * @var list<string>
     */
    private const array MUST_YIELD = [
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_URL',
        'REDIS_DB',
    ];

    #[Test]
    public function every_pin_is_classified_by_the_rule(): void
    {
        $this->assertSame([], array_intersect(self::MUST_NOT_YIELD, self::MUST_YIELD), 'A name cannot both yield and not yield.');

        $this->assertEqualsCanonicalizing(
            [...self::MUST_NOT_YIELD, ...self::MUST_YIELD],
            array_keys(self::pins('env')),
            'Every <env> in phpunit.xml is classified here as yielding or not, by the rule in this docblock. A new pin is added to one list, not left out of both.',
        );
    }

    #[Test]
    public function every_pin_that_must_not_yield_is_forced(): void
    {
        $env = self::pins('env');

        foreach (self::MUST_NOT_YIELD as $name) {
            $this->assertTrue(
                $env[$name]['force'] ?? false,
                "{$name} says what the suite tests against, so an exported value must not replace it: its <env> needs force=\"true\".",
            );
        }
    }

    #[Test]
    public function no_pin_that_must_yield_is_forced(): void
    {
        $env = self::pins('env');

        foreach (self::MUST_YIELD as $name) {
            $this->assertArrayHasKey($name, $env, "{$name} must stay in phpunit.xml as the default a run that exports nothing gets.");
            $this->assertFalse(
                $env[$name]['force'],
                "{$name} is how a run chooses its own disposable state; forcing it would put every concurrent run on the same one.",
            );
        }
    }

    #[Test]
    public function every_forced_entry_is_mirrored_into_server_and_no_other(): void
    {
        $env = self::pins('env');
        $server = self::pins('server');

        /*
         * Name by name first, so that a missing or wrong mirror is reported
         * by the assertion written for it. The set comparison afterwards is
         * for the other direction: a <server> on a name in neither list.
         */
        foreach (self::MUST_NOT_YIELD as $name) {
            $this->assertArrayHasKey($name, $server, "{$name} needs a <server> entry as well as force: without it Laravel's env() still reads the exported value from \$_SERVER.");
            $this->assertSame($env[$name]['value'] ?? null, $server[$name]['value'], "{$name}'s <server> and <env> must name the same value.");
        }

        foreach (self::MUST_YIELD as $name) {
            $this->assertArrayNotHasKey($name, $server, "{$name} must not have a <server> entry: <server> overrides an exported value unconditionally, which would put every concurrent run on one database or one Redis index.");
        }

        $this->assertEqualsCanonicalizing(
            self::MUST_NOT_YIELD,
            array_keys($server),
            'The <server> entries in phpunit.xml are exactly the pins that must not yield. A <server> on any other name makes it unoverridable without this rule having been applied to it.',
        );
    }

    #[Test]
    public function an_exported_variable_loses_to_every_pin_that_must_not_yield(): void
    {
        $env = self::pins('env');
        $decoys = [];

        foreach (self::MUST_NOT_YIELD as $name) {
            $decoys[$name] = 'exported-'.strtolower($name);
        }

        $seen = self::throughPhpunit($decoys, $decoys);
        $untouched = self::throughPhpunit([], []);

        foreach (self::MUST_NOT_YIELD as $name) {
            $pinned = $env[$name]['value'];

            // env() turns some literals into PHP values ("null" is null), so
            // it is compared with a run that exported nothing, not with the
            // text of the pin.
            $this->assertSame(
                ['server' => $pinned, 'env' => $pinned, 'getenv' => $pinned],
                array_diff_key($seen[$name], ['laravel' => true]),
                "An exported {$name}, and one in .env.testing, must lose to phpunit.xml in every reader a test or its subprocesses use.",
            );
            $this->assertSame(
                $untouched[$name]['laravel'],
                $seen[$name]['laravel'],
                "An exported {$name}, and one in .env.testing, must not change what env() returns.",
            );
        }
    }

    #[Test]
    public function an_exported_variable_wins_for_every_pin_that_must_yield_and_the_pin_is_the_default_otherwise(): void
    {
        $env = self::pins('env');
        $exported = [];

        foreach (self::MUST_YIELD as $name) {
            $exported[$name] = 'chosen-'.strtolower($name);
        }

        $chosen = self::throughPhpunit($exported, []);
        $unexported = self::throughPhpunit([], []);

        foreach (self::MUST_YIELD as $name) {
            $this->assertArrayHasKey($name, $env, "{$name} must stay in phpunit.xml as the default a run that exports nothing gets.");
            $this->assertSame(
                $exported[$name],
                $chosen[$name]['laravel'],
                "An exported {$name} is how a run chooses its own disposable state, and it must reach env().",
            );
            $this->assertSame(
                $exported[$name],
                $chosen[$name]['getenv'],
                "An exported {$name} must reach getenv() too, which is what a worker subprocess inherits.",
            );
            $this->assertSame(
                $env[$name]['value'],
                $unexported[$name]['laravel'],
                "A run that exports no {$name} gets phpunit.xml's value.",
            );
        }
    }

    /**
     * The `<env>` or `<server>` entries of phpunit.xml's `<php>` block.
     *
     * @return array<string, array{value: string, force: bool}>
     */
    private static function pins(string $element): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(self::configuration()));
        $pins = [];

        foreach ($xml->php->{$element} as $entry) {
            $name = (string) $entry['name'];

            if (isset($pins[$name])) {
                throw new RuntimeException("phpunit.xml names <{$element} name=\"{$name}\"> twice.");
            }

            $pins[$name] = [
                'value' => (string) $entry['value'],
                'force' => in_array(strtolower((string) $entry['force']), ['true', '1'], true),
            ];
        }

        return $pins;
    }

    private static function configuration(): string
    {
        return dirname(__DIR__, 2).'/phpunit.xml';
    }

    /**
     * What each reader sees after PHPUnit applies phpunit.xml and Laravel
     * loads a `.env.testing`, in a child process whose environment is exactly
     * `$exported` and nothing this process has set.
     *
     * @param  array<string, string>  $exported  the child's environment
     * @param  array<string, string>  $dotenv  the child's .env.testing
     * @return array<string, array{server: ?string, env: ?string, getenv: string|false, laravel: mixed}>
     */
    private static function throughPhpunit(array $exported, array $dotenv): array
    {
        $directory = sys_get_temp_dir().'/phpunit-pins-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($directory);

        $lines = '';
        foreach ($dotenv as $name => $value) {
            $lines .= "{$name}={$value}\n";
        }
        file_put_contents($directory.'/.env.testing', $lines);

        $names = [...self::MUST_NOT_YIELD, ...self::MUST_YIELD];

        $code = <<<'PHP'
            [, $autoload, $configuration, $directory, $names] = $argv;
            require $autoload;
            (new PHPUnit\TextUI\Configuration\PhpHandler)->handle(
                (new PHPUnit\TextUI\XmlConfiguration\Loader)->load($configuration)->php(),
            );
            Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), $directory, '.env.testing')->safeLoad();
            $seen = [];
            foreach (explode(',', $names) as $name) {
                $seen[$name] = [
                    'server' => $_SERVER[$name] ?? null,
                    'env' => $_ENV[$name] ?? null,
                    'getenv' => getenv($name),
                    'laravel' => Illuminate\Support\Env::get($name),
                ];
            }
            echo json_encode($seen, JSON_THROW_ON_ERROR);
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $code, dirname(__DIR__, 2).'/vendor/autoload.php', self::configuration(), $directory, implode(',', $names)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), ...$exported],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the child process.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        unlink($directory.'/.env.testing');
        rmdir($directory);

        if ($status !== 0) {
            throw new RuntimeException("The child process failed ({$status}): {$err}{$out}");
        }

        /** @var array<string, array{server: ?string, env: ?string, getenv: string|false, laravel: mixed}> */
        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }
}
