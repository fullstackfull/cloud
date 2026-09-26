<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Lynomia\Modules\Dns\Application\Services\ConfiguredReservedZones;
use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Naming\DnsSuffix;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reserved-zone guard is silent by construction, so something else says
 * what it is holding.
 *
 * A claim that is refused tells one customer something. A claim that is *not*
 * refused because the list was empty tells nobody anything, and that is the
 * state a deployment starts in. So the estate preflight carries one finding
 * about it, read from the same place the guard reads:
 *
 *   - **Fail** when an entry in `DNS_RESERVED_ZONES` is not a name. The guard
 *     reads the whole list before it compares anything, so it refuses every
 *     claim until the entry is corrected — correct, and an outage.
 *   - **Not a pass while a name beside a platform host is claimable** (F-26).
 *     A reserved name covers itself, its parents and everything beneath it,
 *     never a sibling: with nothing listed and the platform on
 *     `api.lynomia.example`, `www.lynomia.example` is anybody's. Round two
 *     passed that state and said "everything beneath it"; two rows of the
 *     provider below pinned the pass and now pin the warning. A production
 *     preflight blocks on it where the host has nothing reserved above it
 *     and is not itself listed, and on a reservation that holds nothing; any
 *     other run is told, as a warning, and not stopped.
 *   - **Warning** too when one of the platform's own addresses contributed
 *     nothing — and then why, because what is left unheld depends on it:
 *     nothing, for an IP address; the names beneath it, for a single label;
 *     the name itself, for a host with no scheme in front of it — and when a
 *     host is listed exactly with nothing above it, which is complete only if
 *     it is a registrable domain, a thing no check here can know.
 *   - **Pass** otherwise, with a count, the variables it came from and what
 *     that covers — held against the guard below.
 *
 * The finding never quotes a reserved name or a configured value. It names
 * variables and counts entries, which is everything an operator needs to find
 * the line to change and nothing a report printed by a command and rendered
 * in the Control Center should carry.
 */
final class APreflightSaysWhetherThePlatformsOwnNamesAreHeldTest extends TestCase
{
    use RefreshDatabase;

    private const string ID = 'dns.reserved_zones';

    /**
     * Configuration => the status it must produce.
     *
     * @return iterable<string, array{0: list<string>, 1: string, 2: string, 3: CheckStatus}>
     */
    public static function configurations(): iterable
    {
        yield 'the shipped configuration: nothing listed, both addresses local' => [
            [], 'http://localhost:8000', 'http://localhost:5173', CheckStatus::Warning,
        ];
        yield 'a list, and one address that contributed nothing' => [
            ['lynomia.test'], 'http://203.0.113.10:8000', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'a list and both addresses' => [
            ['lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Pass,
        ];
        // Round two passed this, with www. and mail. beside both hosts claimable (F-26).
        yield 'nothing listed, both addresses real names' => [
            [], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'a list beside the platform, and both addresses beneath nothing listed' => [
            ['other.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'a list above one address and not the other' => [
            ['lynomia.test'], 'https://api.lynomia.test', 'https://portal.elsewhere.test', CheckStatus::Warning,
        ];
        // Listed exactly with nothing above: complete only if each is a registrable domain, which is not known here.
        yield 'a list naming exactly the hosts' => [
            ['api.lynomia.test', 'portal.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'nothing listed, the portal on the domain above the control plane' => [
            [], 'https://api.lynomia.test', 'https://lynomia.test', CheckStatus::Pass,
        ];
        yield 'an entry that is not a name' => [
            ['lynomia.test', 'zone_with_underscore.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Fail,
        ];
        yield 'nothing listed, an internationalised address and a real name' => [
            [], 'https://münchen.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'a list, an internationalised address and a real name' => [
            ['lynomia.test'], 'https://münchen.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Pass,
        ];
        yield 'nothing listed, an address with no scheme in front of it' => [
            [], 'panel.lynomia.test', 'https://portal.other.test', CheckStatus::Warning,
        ];
        yield 'nothing listed, an address whose host is not a name' => [
            [], 'https://my_panel.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
    }

    #[Test]
    public function an_estate_preflight_says_exactly_once_whether_the_platforms_own_names_are_held(): void
    {
        $this->configure([], 'http://localhost:8000', 'http://localhost:5173');

        $findings = array_values(array_filter(
            $this->estate(),
            static fn (PreflightFinding $finding): bool => $finding->id === self::ID,
        ));

        $this->assertCount(1, $findings, 'The estate preflight does not report the reserved zones, or reports them twice.');
    }

    /**
     * @param  list<string>  $configured
     */
    #[Test]
    #[DataProvider('configurations')]
    public function each_configuration_produces_its_state_and_only_a_failure_blocks(array $configured, string $app, string $frontend, CheckStatus $expected): void
    {
        $this->configure($configured, $app, $frontend);

        $finding = $this->finding();

        $this->assertSame($expected, $finding->status, $finding->summary);

        /*
         * In a simulation run the one blocking state is the one where the
         * guard is refusing everybody. A warning here must not fail a
         * rehearsal: an estate brought up on an address has nothing to
         * reserve. What a production preflight blocks on is pinned below.
         */
        $this->assertSame($expected === CheckStatus::Fail, $finding->status->blocking());

        if ($expected !== CheckStatus::Pass) {
            $this->assertNotNull($finding->nextAction, 'A finding that is not a pass has to say what to go and do.');
        }
    }

    /**
     * @param  list<string>  $configured
     */
    #[Test]
    #[DataProvider('configurations')]
    public function no_state_quotes_a_reserved_name_or_a_configured_value(array $configured, string $app, string $frontend, CheckStatus $expected): void
    {
        $this->configure($configured, $app, $frontend);

        $finding = $this->finding();
        $said = $finding->summary.' '.($finding->nextAction ?? '');

        $values = [...$configured, $app, $frontend];

        foreach ([$app, $frontend] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host)) {
                $values[] = $host;

                // And the form it is held in, which is not the form it was written in.
                $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

                if (is_string($ascii)) {
                    $values[] = $ascii;
                }
            }
        }

        foreach ($values as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $said, sprintf(
                'The %s finding quotes "%s". It names variables and counts entries; it never repeats what they hold.',
                $expected->value,
                $value,
            ));
        }
    }

    /**
     * F-26, the half round two left: the re-audit's probe, verbatim.
     *
     * With `DNS_RESERVED_ZONES` empty and the platform on
     * `api.lynomia.example` and `app.lynomia.example`, the two hosts, their
     * parents and their children were refused — and `www.lynomia.example` and
     * `mail.lynomia.example` were claimable, while this finding said `pass`
     * and "Each covers itself, every parent of it and everything beneath it".
     * The verdict now follows what a claim meets: not a pass while a name
     * beside a platform host is claimable, and a pass once the domain above
     * them is listed, when those names are refused.
     */
    #[Test]
    public function the_re_audit_probe_is_not_a_pass_while_names_beside_the_platform_are_claimable(): void
    {
        $this->configure([], 'https://api.lynomia.example', 'https://app.lynomia.example');

        $reserved = app(ConfiguredReservedZones::class)->read();

        $this->assertFalse($reserved->protects(DomainName::fromString('www.lynomia.example')));
        $this->assertFalse($reserved->protects(DomainName::fromString('mail.lynomia.example')));

        $finding = $this->finding();

        $this->assertSame(CheckStatus::Warning, $finding->status, $finding->summary);

        foreach (['APP_URL', 'FRONTEND_URL', 'DNS_RESERVED_ZONES'] as $variable) {
            $this->assertStringContainsString($variable, $finding->summary);
        }

        $this->assertStringContainsString('beside', $finding->summary);
        $this->assertStringContainsString('registrable domain', (string) $finding->nextAction);

        $this->configure(['lynomia.example'], 'https://api.lynomia.example', 'https://app.lynomia.example');

        $reserved = app(ConfiguredReservedZones::class)->read();

        $this->assertTrue($reserved->protects(DomainName::fromString('www.lynomia.example')));
        $this->assertTrue($reserved->protects(DomainName::fromString('mail.lynomia.example')));
        $this->assertSame(CheckStatus::Pass, $this->finding()->status);
    }

    /**
     * What a pass claims, held against the guard for every configuration the
     * provider above lists: the name one label up from each platform host,
     * and a name beside each host under it, are refused. The check cannot
     * tell whether a listed name is the registrable domain — that takes a
     * public-suffix list — and its pass says so rather than claiming it.
     *
     * @param  list<string>  $configured
     */
    #[Test]
    #[DataProvider('configurations')]
    public function a_pass_means_no_name_beside_a_platform_host_can_be_claimed(array $configured, string $app, string $frontend, CheckStatus $expected): void
    {
        $this->configure($configured, $app, $frontend);

        $reserved = app(ConfiguredReservedZones::class)->read();
        $finding = $this->finding();

        $this->assertSame($expected, $finding->status, $finding->summary);

        if ($finding->status !== CheckStatus::Pass) {
            return;
        }

        $this->assertNotSame([], $reserved->derived(), 'A pass with no platform host derived: nothing below says what it covers.');

        foreach ($reserved->derived() as $variable => $host) {
            if (substr_count($host, '.') < 2) {
                continue;
            }

            $sibling = 'zz-beside-probe.'.substr($host, (int) strpos($host, '.') + 1);

            $this->assertTrue(
                $reserved->protects(DomainName::fromString($sibling)),
                sprintf('The finding passed while a name beside the host of %s is claimable.', $variable),
            );
        }

        $this->assertStringContainsString('public-suffix', $finding->summary);
        $this->assertStringNotContainsString('everything beneath it.', $finding->summary);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2: string, 3: CheckStatus}>
     */
    public static function productionConfigurations(): iterable
    {
        yield 'the shipped configuration' => [
            [], 'http://localhost:8000', 'http://localhost:5173', CheckStatus::Blocked,
        ];
        yield 'nothing listed, both addresses real names' => [
            [], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Blocked,
        ];
        yield 'a list beside the platform' => [
            ['other.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Blocked,
        ];
        yield 'a list above both addresses' => [
            ['lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Pass,
        ];
        yield 'nothing listed, the portal on the domain above the control plane' => [
            [], 'https://api.lynomia.test', 'https://lynomia.test', CheckStatus::Pass,
        ];
        yield 'a list naming exactly the hosts' => [
            ['api.lynomia.test', 'portal.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Warning,
        ];
        yield 'an entry that is not a name' => [
            ['lynomia.test', 'zone_with_underscore.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Fail,
        ];
    }

    /**
     * A production preflight blocks while nothing is reserved, and while a
     * platform host of three or more labels has nothing reserved above it and
     * is not itself listed: the names beside it are claimable by any account.
     * It does not require `DNS_RESERVED_ZONES` as such — a portal on the
     * domain above the control plane holds every sibling of it, and that row
     * passes with nothing listed. Nor does it block every state in which a
     * sibling is claimable: a host listed exactly is a warning, because it
     * cannot be told apart from a listed registrable domain. A rehearsal is
     * still told, as a warning, and not stopped.
     *
     * @param  list<string>  $configured
     */
    #[Test]
    #[DataProvider('productionConfigurations')]
    public function a_production_preflight_blocks_until_the_list_holds_the_domain_above_every_platform_host(array $configured, string $app, string $frontend, CheckStatus $expected): void
    {
        $this->configure($configured, $app, $frontend);

        $this->app->detectEnvironment(static fn (): string => 'production');

        $finding = $this->finding(PreflightMode::ReadOnlyReal);

        $this->assertSame($expected, $finding->status, $finding->summary);

        if ($expected === CheckStatus::Blocked) {
            $this->assertSame(BlockerReason::Configuration, $finding->blocker);
            $this->assertStringContainsString('DNS_RESERVED_ZONES', (string) $finding->nextAction);
        }

        // The same configuration in a rehearsal of a production estate is told, not stopped.
        $rehearsal = $this->finding(PreflightMode::Simulation);

        $this->assertSame($expected === CheckStatus::Blocked ? CheckStatus::Warning : $expected, $rehearsal->status, $rehearsal->summary);
    }

    /**
     * "Production" is both halves: a read-only-real run AND a production
     * installation. A real read on staging is a rehearsal like any other, so
     * a claimable sibling there is a warning — and so is the naming findings'
     * production rule, which the same test decides. Without this, a
     * `production()` that looked at the mode alone would block every real read
     * on every installation and nothing would notice.
     */
    #[Test]
    public function a_real_read_on_an_installation_that_is_not_production_is_told_and_not_stopped(): void
    {
        $this->configure([], 'https://api.lynomia.test', 'https://portal.lynomia.test');
        config()->set(DnsSuffix::INTERNAL_CONFIG_KEY, 'dc1.reference.example');

        $this->assertFalse($this->app->environment('production'));

        $real = $this->estate(PreflightMode::ReadOnlyReal);

        $this->assertSame(CheckStatus::Warning, $this->named($real, self::ID)->status, $this->named($real, self::ID)->summary);
        $this->assertSame(CheckStatus::Pass, $this->named($real, 'naming.dns_suffix')->status, $this->named($real, 'naming.dns_suffix')->summary);

        // The control: the same configuration on a production installation blocks both.
        $this->app->detectEnvironment(static fn (): string => 'production');

        $production = $this->estate(PreflightMode::ReadOnlyReal);

        $this->assertSame(CheckStatus::Blocked, $this->named($production, self::ID)->status);
        $this->assertSame(CheckStatus::Blocked, $this->named($production, 'naming.dns_suffix')->status);
    }

    /**
     * @param  list<PreflightFinding>  $findings
     */
    private function named(array $findings, string $id): PreflightFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail(sprintf('The estate preflight carries no %s finding.', $id));
    }

    #[Test]
    public function the_shipped_configuration_names_every_variable_it_consulted(): void
    {
        $this->configure([], 'http://localhost:8000', 'http://localhost:5173');

        $summary = $this->finding()->summary;

        foreach (['DNS_RESERVED_ZONES', 'APP_URL', 'FRONTEND_URL'] as $variable) {
            $this->assertStringContainsString($variable, $summary);
        }
    }

    #[Test]
    public function the_shipped_configuration_says_why_each_address_gave_nothing(): void
    {
        $this->configure([], 'http://localhost:8000', 'http://localhost:5173');

        $summary = $this->finding()->summary;

        foreach (['APP_URL', 'FRONTEND_URL'] as $variable) {
            $this->assertStringContainsString(
                sprintf('%s contributed no name: %s', $variable, NoDerivedName::SingleLabel->reason()),
                $summary,
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: NoDerivedName}>
     */
    public static function addressesThatContributeNothing(): iterable
    {
        yield 'unset' => ['', NoDerivedName::Unset];
        yield 'no scheme in front of the host' => ['panel.lynomia.test', NoDerivedName::NotAUrl];
        yield 'an address' => ['http://203.0.113.10:8000', NoDerivedName::IpAddress];
        yield 'a single label' => ['http://localhost:8000', NoDerivedName::SingleLabel];
        yield 'a host that is not a name' => ['https://my_panel.lynomia.test', NoDerivedName::NotADomainName];
    }

    /**
     * The partial warning says why, and says it from the one place that
     * knows. What each reason claims about what can still be claimed is held
     * against the guard in the Dns feature tests.
     */
    #[Test]
    #[DataProvider('addressesThatContributeNothing')]
    public function an_address_that_contributed_nothing_is_reported_with_its_reason(string $app, NoDerivedName $why): void
    {
        $this->configure(['lynomia.test'], $app, 'https://portal.lynomia.test');

        $finding = $this->finding();

        $this->assertSame(CheckStatus::Warning, $finding->status);
        $this->assertStringStartsWith(sprintf('APP_URL contributed no name: %s', $why->reason()), $finding->summary);
    }

    #[Test]
    public function a_failure_says_what_an_entry_has_to_look_like(): void
    {
        $this->configure(['lynomia.test', 'münchen.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test');

        $finding = $this->finding();

        $this->assertSame(CheckStatus::Fail, $finding->status);
        $this->assertStringContainsString('ASCII', (string) $finding->nextAction);
        $this->assertStringContainsString('xn--', (string) $finding->nextAction);
    }

    #[Test]
    public function an_entry_that_is_not_text_is_a_failure_like_any_other(): void
    {
        // Only an edited config/dns.php can put one there; it must not vanish.
        $this->configure([['lynomia.test']], 'https://api.lynomia.test', 'https://portal.lynomia.test');

        $finding = $this->finding();

        $this->assertSame(CheckStatus::Fail, $finding->status, $finding->summary);
        $this->assertStringStartsWith('1 of the 1 entries', $finding->summary);
    }

    #[Test]
    public function a_product_preflight_does_not_repeat_it(): void
    {
        $this->configure([], 'http://localhost:8000', 'http://localhost:5173');

        foreach (Product::cases() as $product) {
            $findings = app(InfrastructurePreflightService::class)
                ->run(new PreflightRequest(PreflightMode::Simulation, PreflightScope::Product, $product->value))
                ->findings;

            foreach ($findings as $finding) {
                $this->assertNotSame(self::ID, $finding->id, sprintf(
                    'A %s preflight carries %s. It is a fact about the deployment and about no one product; the estate run says it once.',
                    $product->value,
                    self::ID,
                ));
            }
        }
    }

    #[Test]
    public function the_address_that_contributed_nothing_is_the_one_named(): void
    {
        $this->configure(['lynomia.test'], 'https://api.lynomia.test', 'http://203.0.113.10:5173');

        $this->assertStringStartsWith('FRONTEND_URL contributed no name', $this->finding()->summary);

        $this->configure(['lynomia.test'], 'http://203.0.113.10:8000', 'https://portal.lynomia.test');

        $this->assertStringStartsWith('APP_URL contributed no name', $this->finding()->summary);
    }

    #[Test]
    public function a_pass_counts_the_entries_and_names_where_they_came_from(): void
    {
        $this->configure(['lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test');

        $summary = $this->finding()->summary;

        $this->assertStringContainsString('3 name(s) reserved', $summary);

        foreach (['DNS_RESERVED_ZONES', 'APP_URL', 'FRONTEND_URL'] as $variable) {
            $this->assertStringContainsString($variable, $summary);
        }
    }

    #[Test]
    public function the_command_prints_the_failure_without_the_value_that_caused_it(): void
    {
        $this->configure(['lynomia.test', 'zone_with_underscore.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test');

        Artisan::call('infra:preflight', ['--mode' => 'simulation']);
        $output = Artisan::output();

        $line = null;

        foreach (explode("\n", $output) as $candidate) {
            if (str_contains($candidate, self::ID)) {
                $line = $candidate;
            }
        }

        $this->assertNotNull($line, "The command printed no line for the reserved zones:\n".$output);
        $this->assertStringContainsString('[FAIL]', $line);

        foreach (['zone_with_underscore', 'lynomia.test'] as $value) {
            $this->assertStringNotContainsString($value, $output);
        }
    }

    /**
     * @param  list<mixed>  $configured
     */
    private function configure(array $configured, string $app, string $frontend): void
    {
        config()->set('dns.reserved_zones', $configured);
        config()->set('app.url', $app);
        config()->set('app.frontend_url', $frontend);
    }

    /**
     * @return list<PreflightFinding>
     */
    private function estate(PreflightMode $mode = PreflightMode::Simulation): array
    {
        return app(InfrastructurePreflightService::class)
            ->run(PreflightRequest::estate($mode))
            ->findings;
    }

    private function finding(PreflightMode $mode = PreflightMode::Simulation): PreflightFinding
    {
        foreach ($this->estate($mode) as $finding) {
            if ($finding->id === self::ID) {
                return $finding;
            }
        }

        $this->fail('The estate preflight carries no '.self::ID.' finding.');
    }
}
