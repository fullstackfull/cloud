<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Tests\Support\Queue\QueuedClasses;
use Tests\TestCase;

/**
 * `error.details` carries what the caller already knows, and nothing they
 * would learn from us.
 *
 * ---------------------------------------------------------------------------
 * The defect
 * ---------------------------------------------------------------------------
 *
 * The API renderer used to publish a domain exception's whole context as
 * `error.details`. A context is written for the engineer reading the log, so
 * it names the provider, the driver, the node and the configuration key a
 * credential is read from — and on the customer routes that let such an
 * exception escape, all of it became a response body. `ErrorCatalogue` said
 * the opposite: that an internal identifier "does not surface unless the
 * catalogue author asked for it". The sentence was kept out of the response;
 * `details` published the same facts beside it.
 *
 * Two routes were found doing it. The count is not the exposure. Every class
 * with a forbidden word in a context key whose code the customer catalogue
 * answers could, and the ones that did not were held off the wire by hand —
 * a rewrap here, a catch there, a guard assertion listing the keys it hoped
 * were absent. So the fix is a boundary rather than two patches:
 * `DomainException::publishedContext()` returns only what a class declares
 * with `publishing(...)`, and the renderer reads that and nothing else.
 *
 * ---------------------------------------------------------------------------
 * How the rows are arranged
 * ---------------------------------------------------------------------------
 *
 *  - **The renderer**, driven through fixture exceptions thrown from a test
 *    route: undeclared keys stay off the wire, declared keys arrive, the
 *    sentence is still written from the whole context, and the engine refuses
 *    a key that names the platform however it was declared.
 *  - **Real customer routes** that published provider identity and
 *    configuration key paths before the boundary existed.
 *  - **The source**: every `publishing(...)` call in the application is read,
 *    and the list of what is declared is compared with the reviewed list
 *    below. A source scanner can only promise something about the forms it
 *    reads; the engine's filter is what holds for the forms it cannot.
 */
final class ErrorDetailsAreOnlyWhatTheCallerAlreadyKnowsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every declaration in the application, reviewed.
     *
     * Each key is something the caller already knows: a value they sent, a
     * field on their own form, the state of their own resource. A new entry
     * here is a decision that a caller is owed a detail, and it is made by
     * adding the key to this list in the same change that declares it — which
     * is the point: widening the response is something somebody says out loud.
     *
     * Keyed by fully-qualified class name rather than by file name, so two
     * classes that share a basename cannot merge into one row.
     *
     * @var array<class-string, list<string>>
     */
    private const array INVENTORY = [
        'Lynomia\Modules\Billing\Domain\Exceptions\SubscriptionAlreadyEndedException' => ['status'],
        'Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedControlUnavailableException' => ['action', 'dedicated_server_id', 'safe_to_retry'],
        'Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException' => ['in_flight_kind', 'status'],
        'Lynomia\Modules\Dedicated\Domain\Exceptions\PowerOperationIndeterminateException' => ['indeterminate', 'safe_to_retry'],
        // The zone name the customer submitted, told back (F-26 x F-27).
        'Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException' => ['zone'],
        'Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException' => ['value'],
        'Lynomia\Modules\Identity\Domain\Exceptions\EmailAddressNotVerifiedException' => ['email', 'resend_endpoint'],
        // When this account's own wait on an address ends (F-17 x F-27).
        'Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException' => ['retry_at'],
        'Lynomia\Modules\Identity\Domain\Exceptions\TwoFactorRequiredException' => ['challenge_token'],
        'Lynomia\Modules\Ipam\Domain\Exceptions\InvalidHostnameException' => ['reason'],
        'Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException' => ['idempotency_key', 'plan_id'],
        'Lynomia\Modules\Shared\Domain\Exceptions\AccountPermissionRequiredException' => ['required_permission'],
        'Lynomia\Modules\Shared\Domain\Exceptions\IdempotencyKeyRejectedException' => ['field', 'header'],
        'Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPanelSessionUnavailableException' => ['status'],
        'Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeRefusedException' => ['refusals'],
        'Lynomia\Modules\Vps\Domain\Exceptions\VpsOperationInFlightException' => ['in_flight_kind'],
        'Lynomia\Modules\Wallet\Domain\Exceptions\UnsupportedAccountCurrencyException' => ['currency'],
    ];

    private const string NODE = 'kw-node-07.estate.lynomia.internal';

    private const string CONFIGURATION_KEY = 'services.hosting.whm_token_for_node_seven';

    // ---- the renderer ------------------------------------------------------

    #[Test]
    public function a_key_nobody_declared_does_not_reach_the_response(): void
    {
        $response = $this->thrown(self::refusal([
            'order_id' => '01JD0000000000000000000000',
            'node' => self::NODE,
            'configuration_key' => self::CONFIGURATION_KEY,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'test.refused');

        $this->assertNothingPublished($response);

        $this->assertBodyNames($response, []);
    }

    #[Test]
    public function a_declared_key_is_published_and_nothing_beside_it(): void
    {
        $response = $this->thrown(self::refusal([
            'order_id' => '01JD0000000000000000000000',
            'node' => self::NODE,
        ], 'order_id'));

        $this->assertSame(['order_id' => '01JD0000000000000000000000'], $response->json('error.details'));
        $this->assertBodyNames($response, []);
    }

    /**
     * The half of the context that did not change.
     *
     * The catalogue's `:placeholders` are still filled from everything the
     * exception carries, so no response's sentence moves: only `details` is
     * narrowed. A detail the sentence names is the catalogue author's choice,
     * made per sentence, which is what `ErrorCatalogue` always said it was.
     */
    #[Test]
    public function the_sentence_is_still_written_from_the_whole_context(): void
    {
        // Load the real group first: a line added to an unloaded group would
        // stand in for the whole file.
        __('errors.request_failed');
        Lang::addLines(['errors.test.refused' => 'Refused until :until.'], 'en');

        $response = $this->thrown(self::refusal([
            'until' => '2026-10-01',
            'node' => self::NODE,
        ]));

        $response->assertJsonPath('error.message', 'Refused until 2026-10-01.');
        $this->assertNothingPublished($response);
    }

    #[Test]
    public function a_declared_key_the_context_does_not_carry_is_not_invented(): void
    {
        $response = $this->thrown(self::refusal([
            'order_id' => '01JD0000000000000000000000',
        ], 'order_id', 'invoice_id'));

        // Exactly one key: not `invoice_id: null` beside it.
        $this->assertSame(['order_id' => '01JD0000000000000000000000'], $response->json('error.details'));
    }

    /**
     * The engine refuses what the scanner might not see.
     *
     * A key that names the platform is dropped at read time whatever its case,
     * whatever digits are in it and whichever way it was declared — so the
     * forms no source scanner reads (a key built at runtime, a constant, a
     * spread, a call through a variable) end in the same place as the ones it
     * does.
     */
    #[Test]
    public function a_declared_key_that_names_the_platform_is_dropped_however_it_is_spelled(): void
    {
        $forbidden = [
            'node' => self::NODE,
            'node2_hostname' => self::NODE,
            'ClusterId' => 'kw-cluster-1',
            'upstream_provider' => 'cloudflare',
            'configuration_key' => self::CONFIGURATION_KEY,
            'DRIVER' => 'sy_registry',
            'panel_credentials_ref' => 'vault:panel/root',
        ];

        $response = $this->thrown(self::refusal(
            ['order_id' => '01JD0000000000000000000000', ...$forbidden],
            'order_id',
            ...array_keys($forbidden),
        ));

        $this->assertSame(['order_id' => '01JD0000000000000000000000'], $response->json('error.details'));
        $this->assertBodyNames($response, []);
    }

    /**
     * The shape that defeated a source scanner, answered by the engine.
     *
     * `parent::publishing(...)` is ordinary PHP. A scanner that read only
     * `->publishing(` missed it, and patched into a real class it put a node
     * name and a configuration key on an authenticated customer route with
     * every source row green.
     */
    #[Test]
    public function a_declaration_through_parent_is_held_to_the_same_rule(): void
    {
        $refusal = new class('The engineer\'s sentence, written for the log.') extends DomainException
        {
            public function errorCode(): string
            {
                return 'test.refused';
            }

            public function carrying(): static
            {
                $this->withContext([
                    'currency' => 'KWD',
                    'node' => 'kw-node-07.estate.lynomia.internal',
                    'configuration_key' => 'services.hosting.whm_token_for_node_seven',
                ]);

                parent::publishing('currency', 'node', 'configuration_key');

                return $this;
            }
        };

        $response = $this->thrown($refusal->carrying());

        $this->assertSame(['currency' => 'KWD'], $response->json('error.details'));
        $this->assertBodyNames($response, []);
    }

    #[Test]
    public function declaring_again_adds_to_what_was_declared(): void
    {
        $refusal = new class('The engineer\'s sentence, written for the log.') extends DomainException
        {
            public function errorCode(): string
            {
                return 'test.refused';
            }

            public function carrying(): static
            {
                $this->withContext(['order_id' => '01JD0000000000000000000000', 'invoice_id' => '01JD1111111111111111111111']);
                $this->publishing('order_id');
                $this->publishing('invoice_id');

                return $this;
            }
        };

        $this->assertSame(
            ['order_id' => '01JD0000000000000000000000', 'invoice_id' => '01JD1111111111111111111111'],
            $this->thrown($refusal->carrying())->json('error.details'),
        );
    }

    /**
     * The list is the base class's, and a subclass cannot write it.
     *
     * A subclass declaring a property of the same name gets a second property
     * the base never reads. The payload is deliberately a key the caller would
     * be owed — nothing the vocabulary filter would drop anyway — so this row
     * can only pass because of the property's visibility.
     */
    #[Test]
    public function a_subclass_that_writes_its_own_published_list_publishes_nothing_by_it(): void
    {
        $refusal = new class('The engineer\'s sentence, written for the log.') extends DomainException
        {
            /** @var list<string> */
            protected array $published = ['order_id'];

            public function errorCode(): string
            {
                return 'test.refused';
            }

            public function carrying(): static
            {
                return $this->withContext(['order_id' => '01JD0000000000000000000000']);
            }
        };

        $this->assertNothingPublished($this->thrown($refusal->carrying()));
    }

    /**
     * What PHP enforces, which is why the rows above can be few.
     *
     * `final` and `private` are not observable by a behavioural test in every
     * combination — a subclass that tried to override a final method would not
     * load at all — so they are pinned here, where removing one is a red row
     * rather than a silent widening.
     */
    #[Test]
    public function the_boundary_is_built_so_that_the_engine_enforces_it(): void
    {
        $read = new ReflectionMethod(DomainException::class, 'publishedContext');
        $this->assertTrue($read->isFinal() && $read->isPublic(), 'publishedContext() must be final and public.');

        $declare = new ReflectionMethod(DomainException::class, 'publishing');
        $this->assertTrue($declare->isFinal() && $declare->isProtected(), 'publishing() must be final and protected: a public one lets any caller widen any exception.');

        $list = new ReflectionProperty(DomainException::class, 'published');
        $this->assertTrue($list->isPrivate(), 'The declared list must be private, or a subclass can write it without calling publishing().');

        $words = new ReflectionClassConstant(DomainException::class, 'NEVER_PUBLISHED');
        $this->assertTrue($words->isFinal(), 'The vocabulary must be final, or a subclass can redeclare it empty.');

        $predicate = new ReflectionMethod(DomainException::class, 'namesThePlatform');
        $this->assertTrue($predicate->isPrivate(), 'The vocabulary check must be private, or a subclass can override it.');
    }

    // ---- real customer routes ---------------------------------------------

    /**
     * One of the two routes the audit found.
     *
     * Claiming a zone asks the provider whether it can create zones before
     * anything is written, and with Cloudflare selected and no token that
     * question throws from the connection. The customer used to be told the
     * provider's name and the configuration key the token is read from.
     */
    #[Test]
    public function claiming_a_zone_does_not_name_the_dns_provider_or_where_its_token_is_read_from(): void
    {
        config()->set('billing.providers.dns', 'cloudflare');
        config()->set('services.cloudflare.api_token', '');

        [$customer, $owner] = $this->accountWithOwner();

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'dns.not_configured');

        $this->assertNothingPublished($response);

        $this->assertBodyNames($response, ['cloudflare', 'services.cloudflare', 'api_token']);
    }

    #[Test]
    public function claiming_a_zone_does_not_name_a_dns_driver_this_build_lacks(): void
    {
        config()->set('billing.providers.dns', 'route53');

        [$customer, $owner] = $this->accountWithOwner();

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'dns.unknown_driver');

        $this->assertNothingPublished($response);

        $this->assertBodyNames($response, ['route53', 'cloudflare']);
    }

    /**
     * A name held at a registrar whose driver this build does not contain.
     *
     * Not wrapped on its way out: the nameserver, contact, lock and
     * authorisation-code routes all build the registrar from the domain's own
     * row, and the driver name used to arrive as `error.details.driver`.
     */
    #[Test]
    public function a_name_at_an_unknown_registrar_does_not_name_the_driver(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $domain = Domain::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'mine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'retired_registrar',
            'expires_at' => now()->addYear(),
        ]);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->putJson('/api/v1/domains/'.$domain->getKey().'/nameservers', [
                'nameservers' => ['ns1.example.test', 'ns2.example.test'],
            ])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'domain.unknown_registrar_driver');

        $this->assertNothingPublished($response);

        $this->assertBodyNames($response, ['retired_registrar']);
    }

    // ---- the source --------------------------------------------------------

    #[Test]
    public function every_declaration_in_the_application_is_one_somebody_reviewed(): void
    {
        $declared = [];

        foreach (self::declarations() as $declaration) {
            foreach ($declaration['keys'] as $key) {
                $declared[$declaration['class']][] = $key;
            }
        }

        foreach ($declared as $class => $keys) {
            $keys = array_values(array_unique($keys));
            sort($keys);
            $declared[$class] = $keys;
        }

        ksort($declared);

        $reviewed = self::INVENTORY;
        ksort($reviewed);

        $this->assertSame(
            $reviewed,
            $declared,
            'The keys an exception publishes as error.details changed. A new key is a decision that the caller '
            .'already knows the value — a value they sent, a field on their own form, the state of their own '
            .'resource. If that is true, add it to INVENTORY in the same change; if it is something they would '
            .'learn from us, it belongs in the log.',
        );
    }

    #[Test]
    public function no_declaration_in_the_application_names_the_platform(): void
    {
        $offending = [];

        foreach (self::declarations() as $declaration) {
            foreach ($declaration['keys'] as $key) {
                if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || self::namesThePlatform($key)) {
                    $offending[] = $declaration['where'].' publishes "'.$key.'"';
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'A declared key names something the caller would learn from us, or is not a plain snake_case name. '
            .'The engine would drop it at read time; this row refuses it on the day it is written.',
        );
    }

    #[Test]
    public function every_declaration_is_written_as_literals_a_reader_can_check(): void
    {
        $opaque = [];

        foreach (self::declarations() as $declaration) {
            if (! $declaration['literal']) {
                $opaque[] = $declaration['where'];
            }
        }

        $this->assertSame(
            [],
            $opaque,
            'publishing() was called with something other than string literals — a constant, a variable, a spread '
            .'or a first-class callable. What it publishes cannot be reviewed from the source, so the inventory '
            .'above would be a claim about some of the declarations rather than all of them.',
        );
    }

    /**
     * The instrument, checked against source written to break it.
     */
    #[Test]
    public function the_scanner_reads_every_way_php_calls_the_method_and_nothing_else(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Somewhere\Else;

        /**
         * A class that never calls `publishing()` in its docblock, and says so
         * with an unclosed publishing( here, which a raw-text scan would read.
         */
        final class Fixture extends Base
        {
            // $this->publishing('from_a_comment');
            private string $text = "->publishing('from_a_string')";

            protected function publishing(string ...$keys): static { return $this; }

            public function all(): void
            {
                $this->publishing('arrow');
                $this?->publishing('nullsafe');
                parent::publishing('parent_call');
                self::publishing('self_call');
                static::publishing('static_call');
                $class::publishing('variable_class');
                $this->PUBLISHING('upper_case');
                $this->publishing('node2_hostname');
                $this->publishing(self::SNEAK);
                $this->publishing(...$keys);
                $this->publishing(...);
                $this->publishing('two', "three");
            }
        }
        PHP;

        $found = [];
        foreach (self::declarationsIn($source, 'fixture.php') as $declaration) {
            $this->assertSame('Somewhere\Else\Fixture', $declaration['class']);
            $found[] = [$declaration['keys'], $declaration['literal']];
        }

        $this->assertSame([
            [['arrow'], true],
            [['nullsafe'], true],
            [['parent_call'], true],
            [['self_call'], true],
            [['static_call'], true],
            [['variable_class'], true],
            [['upper_case'], true],
            [['node2_hostname'], true],
            [[], false],
            [[], false],
            [[], false],
            [['two', 'three'], true],
        ], $found);

        $this->assertTrue(self::namesThePlatform('node2_hostname'), 'A digit must not hide a forbidden word.');
    }

    /**
     * The boundary is a claim about the whole application, so the scan is.
     *
     * The roots are the ones `composer.json` autoloads production classes
     * from, read here independently of the scanner, so a root cannot be
     * dropped from the sweep by editing a list in a test.
     */
    #[Test]
    public function the_scan_covers_every_root_composer_autoloads(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $this->assertIsArray($composer);

        $expected = [];
        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $directories) {
            foreach ((array) $directories as $directory) {
                $expected[] = rtrim(base_path((string) $directory), '/');
            }
        }
        sort($expected);

        $walked = array_values(QueuedClasses::roots());
        sort($walked);

        $this->assertSame($expected, $walked);
        $this->assertContains(base_path('app'), $walked);
        $this->assertContains(base_path('src'), $walked);

        foreach ($walked as $root) {
            $this->assertNotSame([], self::phpFilesUnder($root), $root.' holds no PHP file; the sweep read nothing there.');
        }
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param  array<string, scalar|null>  $context
     */
    private static function refusal(array $context, string ...$declared): DomainException
    {
        $refusal = new class('The engineer\'s sentence, written for the log.') extends DomainException
        {
            public function errorCode(): string
            {
                return 'test.refused';
            }

            /**
             * @param  array<string, scalar|null>  $context
             */
            public function carrying(array $context, string ...$declared): static
            {
                $this->withContext($context);

                return $declared === [] ? $this : $this->publishing(...$declared);
            }
        };

        return $refusal->carrying($context, ...$declared);
    }

    private function thrown(DomainException $refusal): TestResponse
    {
        Route::middleware('api')->get('/api/v1/__test/details', static function () use ($refusal): never {
            throw $refusal;
        });

        return $this->getJson('/api/v1/__test/details');
    }

    private function assertNothingPublished(TestResponse $response): void
    {
        $this->assertNull(
            $response->json('error.details'),
            'error.details was published: '.json_encode($response->json('error.details')),
        );
    }

    /**
     * @param  list<string>  $also
     */
    private function assertBodyNames(TestResponse $response, array $also): void
    {
        $body = strtolower((string) $response->getContent());

        foreach ([self::NODE, self::CONFIGURATION_KEY, 'kw-cluster-1', 'vault:panel', 'sy_registry', ...$also] as $internal) {
            $this->assertStringNotContainsString(strtolower($internal), $body, $internal.' reached the response body.');
        }
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function accountWithOwner(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    private static function namesThePlatform(string $key): bool
    {
        foreach (DomainException::NEVER_PUBLISHED as $word) {
            if (str_contains(strtolower($key), $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `publishing(...)` call under the production autoload roots.
     *
     * @return list<array{class: string, keys: list<string>, literal: bool, where: string}>
     */
    private static function declarations(): array
    {
        $found = [];

        foreach (QueuedClasses::roots() as $root) {
            foreach (self::phpFilesUnder($root) as $path) {
                $relative = substr($path, strlen(base_path()) + 1);

                foreach (self::declarationsIn((string) file_get_contents($path), $relative) as $declaration) {
                    $found[] = $declaration;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The calls in one file, read by PHP's own tokeniser.
     *
     * Tokens rather than text, because a comment or a string that mentions
     * the method is not a call, and because a call is recognised by what
     * precedes it: `->`, `?->` or `::` — the last of which covers `parent::`,
     * `self::`, `static::` and a class held in a variable. Method names are
     * case-insensitive in PHP, so the name is too. A declaration whose
     * arguments are not all string literals is reported as opaque rather than
     * guessed at.
     *
     * @return list<array{class: string, keys: list<string>, literal: bool, where: string}>
     */
    private static function declarationsIn(string $source, string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $namespace = '';
        $class = '';
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j])) {
                        $namespace .= $tokens[$j][1];
                    }
                }

                continue;
            }

            if (in_array($token[0], [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true)) {
                $before = $tokens[$i - 1] ?? null;
                $after = $tokens[$i + 1] ?? null;

                if (is_array($before) && in_array($before[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }

                if (is_array($after) && $after[0] === T_STRING) {
                    $class = ltrim($namespace.'\\'.$after[1], '\\');
                }

                continue;
            }

            if ($token[0] !== T_STRING || strtolower($token[1]) !== 'publishing') {
                continue;
            }

            $before = $tokens[$i - 1] ?? null;

            if (! is_array($before) || ! in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $arguments = [[]];
            $depth = 0;

            for ($j = $i + 1; $j < $count; $j++) {
                $part = $tokens[$j];

                if (in_array($part, ['(', '[', '{'], true) || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($part, [')', ']', '}'], true)) {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                } elseif ($part === ',' && $depth === 1) {
                    $arguments[] = [];

                    continue;
                }

                $arguments[array_key_last($arguments)][] = $part;
            }

            $arguments = array_values(array_filter($arguments, static fn (array $argument): bool => $argument !== []));
            $keys = [];
            $literal = true;

            foreach ($arguments as $argument) {
                if (count($argument) !== 1 || ! is_array($argument[0]) || $argument[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $literal = false;

                    continue;
                }

                $text = $argument[0][1];
                $keys[] = $text[0] === '"'
                    ? stripcslashes(substr($text, 1, -1))
                    : str_replace(['\\\\', "\\'"], ['\\', "'"], substr($text, 1, -1));
            }

            $found[] = [
                'class' => $class,
                'keys' => $literal ? $keys : [],
                'literal' => $literal && $arguments !== [],
                'where' => $file.':'.$token[2],
            ];
        }

        return $found;
    }
}
