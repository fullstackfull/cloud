<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
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
 * about it, read from the same place the guard reads, in three states:
 *
 *   - **Fail** when an entry in `DNS_RESERVED_ZONES` is not a name. The guard
 *     reads the whole list before it compares anything, so it refuses every
 *     claim until the entry is corrected — correct, and an outage.
 *   - **Warning** when nothing is held, or when one of the platform's own
 *     addresses contributed nothing — and then why, because what is left
 *     unheld depends on it: nothing, for an IP address; the names beneath it,
 *     for a single label; the name itself, for a host with no scheme in front
 *     of it. Worth saying; not worth stopping a deployment for, because the
 *     guard is refusing nobody it should not, and the shipped configuration
 *     starts here.
 *   - **Pass** otherwise, with a count and the variables it came from.
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
        yield 'nothing listed, both addresses real names' => [
            [], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Pass,
        ];
        yield 'an entry that is not a name' => [
            ['lynomia.test', 'zone_with_underscore.lynomia.test'], 'https://api.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Fail,
        ];
        yield 'nothing listed, an internationalised address and a real name' => [
            [], 'https://münchen.lynomia.test', 'https://portal.lynomia.test', CheckStatus::Pass,
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
         * The one blocking state is the one where the guard is refusing
         * everybody. A warning here must not fail a deployment pipeline: an
         * estate brought up on an address has nothing to reserve.
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
    private function estate(): array
    {
        return app(InfrastructurePreflightService::class)
            ->run(PreflightRequest::estate(PreflightMode::Simulation))
            ->findings;
    }

    private function finding(): PreflightFinding
    {
        foreach ($this->estate() as $finding) {
            if ($finding->id === self::ID) {
                return $finding;
            }
        }

        $this->fail('The estate preflight carries no '.self::ID.' finding.');
    }
}
