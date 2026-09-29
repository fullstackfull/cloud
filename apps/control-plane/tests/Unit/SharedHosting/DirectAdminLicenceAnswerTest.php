<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-14: what a DirectAdmin node says about its licence, read rather than
 * assumed.
 *
 * Before this, every read command came back as unvalidated `parse_str`
 * output — only mutations were refused for carrying no `error` field — and
 * `licenceStatus()` answered `valid: true` for any body at all, with the
 * state defaulting to `'active'`. An unreadable node was recorded licensed and
 * scheduled for paid orders. cPanel fails closed on the same input.
 *
 * The rows below are grouped by what they refuse. Each refused row asserts the
 * READING — `valid` together with the state and the expiry — rather than the
 * verdict alone, because a refusal and "read, but already expired" collapse
 * into the same `valid: false`, and a rule whose only effect is on a past date
 * is invisible to an oracle that reads `valid` and nothing else.
 *
 * Every row is its own test case with its own `Http::fake()`. A second
 * `Http::fake(['*' => …])` in one test merges into the stub list behind the
 * first and never answers, so a loop of fakes in one method asserts the first
 * body over and over while its assertion count looks healthy.
 */
final class DirectAdminLicenceAnswerTest extends TestCase
{
    private const string FUTURE = '2099-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed, so "the past" and "the future" in these rows do not move.
        CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The answer the finding names
    // ------------------------------------------------------------------

    #[Test]
    public function a_licence_answer_that_names_no_licence_is_not_a_licence(): void
    {
        // The audit's case: no error field, nothing a licence answer carries.
        $status = $this->licence('foo=bar&baz=qux');

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
        $this->assertNull($status->expiresAt);
    }

    #[Test]
    public function a_licence_answer_with_no_status_word_is_not_assumed_active(): void
    {
        // `?? 'active'` was the entry state. A future expiry alone is not the
        // panel saying the licence serves.
        $status = $this->licence('expires='.self::FUTURE);

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wordsThatDoNotServe(): array
    {
        return [
            'expired' => ['expired'],
            'suspended' => ['suspended'],
            'invalid' => ['invalid'],
            'inactive' => ['inactive'],
            'revoked' => ['revoked'],
        ];
    }

    #[Test]
    #[DataProvider('wordsThatDoNotServe')]
    public function a_panel_that_says_its_licence_does_not_serve_is_believed(string $word): void
    {
        $status = $this->licence('status='.$word.'&expires='.self::FUTURE);

        $this->assertFalse($status->valid);
        $this->assertSame($word, $status->state);
    }

    #[Test]
    public function a_word_this_repository_does_not_know_is_not_read_as_serving(): void
    {
        $status = $this->licence('status=pending_review_by_vendor_team&expires='.self::FUTURE);

        $this->assertFalse($status->valid);
        // Not the vendor's word verbatim: the column is 32 characters and an
        // unknown word is an unconfirmed licence, not a state.
        $this->assertSame('unconfirmed', $status->state);
        $this->assertStringContainsString('pending_review_by_vendor_team', (string) $status->detail);
    }

    #[Test]
    public function every_status_the_answer_carries_must_serve_not_only_the_first(): void
    {
        $status = $this->licence('status=active&state=expired&expires='.self::FUTURE);

        $this->assertFalse($status->valid);
        $this->assertSame('expired', $status->state);
    }

    #[Test]
    public function an_expiry_in_the_past_is_not_a_licence(): void
    {
        $status = $this->licence('status=active&expires=2020-01-01');

        $this->assertFalse($status->valid);
        $this->assertSame('expired', $status->state);
        $this->assertSame('2020-01-01', $status->expiresAt?->toDateString());
    }

    #[Test]
    public function the_earliest_expiry_the_answer_carries_decides(): void
    {
        $status = $this->licence('status=active&expires='.self::FUTURE.'&expiry=2020-01-01');

        $this->assertFalse($status->valid);
        $this->assertSame('2020-01-01', $status->expiresAt?->toDateString());
    }

    #[Test]
    public function an_expiry_that_cannot_be_read_is_not_a_licence_without_one(): void
    {
        $status = $this->licence('status=active&expires=not-a-date-at-all');

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
        $this->assertNull($status->expiresAt);
    }

    // ------------------------------------------------------------------
    // What the licence command accepts
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function expiriesThatAreRead(): array
    {
        return [
            'ISO date' => ['2099-12-31', '2099-12-31'],
            'ISO 8601 with offset' => ['2099-12-31T23:59:59%2B00:00', '2099-12-31'],
            'ISO 8601 zulu' => ['2099-12-31T23:59:59Z', '2099-12-31'],
            'RFC 2822' => ['Thu,+31+Dec+2099+00:00:00+%2B0000', '2099-12-31'],
            'date and clock' => ['2099-12-31+23:59:59', '2099-12-31'],
            'fractional seconds' => ['2099-12-31+00:00:00.000000', '2099-12-31'],
            'US order, unambiguous' => ['12/31/2099', '2099-12-31'],
            'dotted day first, unambiguous' => ['31.12.2099', '2099-12-31'],
            'month name' => ['31+December+2099', '2099-12-31'],
            'month name first' => ['December+31,+2099', '2099-12-31'],
            'year first, slashed' => ['2099/12/31', '2099-12-31'],
            'two-digit year, attached compact offset' => ['15-Jun-27%2B0000', '2027-06-15'],
            'unix seconds' => ['4102358400', '2099-12-31'],
            'no expiry given' => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('expiriesThatAreRead')]
    public function a_serving_licence_is_read_with_its_expiry(string $encoded, string $expected): void
    {
        $status = $this->licence('status=active'.($encoded === '' ? '' : '&expires='.$encoded));

        $this->assertTrue($status->valid, 'A readable, serving licence was refused.');
        $this->assertSame('active', $status->state);
        $this->assertSame($expected === '' ? null : $expected, $status->expiresAt?->toDateString());
    }

    /**
     * Each of these was accepted before, and each records a licence the panel
     * did not grant — most of them live, several for thousands of years.
     *
     * `+` has to arrive as `%2B`: `parse_str` turns a bare `+` into a space,
     * which is why a naive probe of the offset rows looks safe.
     *
     * @return array<string, array{string}>
     */
    public static function expiriesThatNameNoDayTheyCanBeTrustedFor(): array
    {
        return [
            // Relative: resolved against the clock, so it sold on every sync.
            'tomorrow' => ['tomorrow'],
            'a relative year' => ['%2B1+year'],
            'a date moved by a relative part' => ['2099-12-31+%2B1+day'],
            'ISO week date' => ['2099-W01-1'],
            'a relative part with no digits' => ['2099-12-31+next+hour'],
            // Day and month the parser cannot tell apart.
            'd/m read as m/d' => ['12/01/2026'],
            'dashed, ambiguous' => ['05-04-2099'],
            'dotted, ambiguous' => ['05.04.2099'],
            // A weekday that disagrees with its date moves the date.
            'a weekday that is not that date' => ['Fri,+31+Dec+2099'],
            // Rolled forward by the parser rather than refused.
            'an invalid calendar date' => ['2099-02-31'],
            'a sixtieth second' => ['2099-12-31+23:59:60'],
            // A leading token read as a zone.
            'a leading military zone' => ['V2099-01-01'],
            'a leading zone letter the parser does not warn about' => ['A2099-12-31'],
            // Two zones: the parser keeps one and warns; which instant the
            // panel meant is not in the value.
            'two zones' => ['2099-12-31+EST+PST'],
            // The year field wider or narrower than four digits.
            'five-digit year behind a zone' => ['UTC%2B22099-01-01'],
            'wider year behind a zone' => ['ACDT%2B29999-01-01'],
            'year-last, eight digits' => ['01/31/99999999'],
            'year-first, eight digits' => ['99999999/01/31'],
            'year-first, five digits' => ['99999/12/31'],
            'day field five digits' => ['2099/12/99999'],
            'month field ten digits' => ['01.1111111111.2099'],
            'day field glued to a clock' => ['2099-01-012:45'],
            'two-digit year, numeric' => ['30-01-12'],
            'five-digit ISO' => ['10000-01-01'],
            // A second number wide enough to be a year.
            'a bare four digits after a date' => ['2020-01-01+9999'],
            'after a two-digit-year date' => ['01-Jan-20+9999'],
            'glued onto the year' => ['01+Jan+20209999'],
            'after a day-first date' => ['31-12-2099+9999'],
            'after a clock, behind a T' => ['Jan+1+20+23:59:59T2035'],
            'a run an offset would swallow part of' => ['Jan+1+20+23:59:59%2B868783'],
            'before a separated offset' => ['01-Jan-20+9999+%2B0000'],
            'behind a colonned offset' => ['2020-01-01+00:00%2B00:00.9999'],
            // Its own year spelled like an offset: only the whitespace
            // lookbehind and the complete-date limb keep `-1200` a year.
            'a year that is also an offset\'s spelling' => ['31-12-1200+9999'],
            'a second year glued in front of the day' => ['3401+Jan+2020'],
            // Disclosed over-refusal: a bare HHMM clock is the second year's
            // shape exactly, and no test on the token separates them.
            'a bare clock-shaped four digits' => ['31-Dec-69+2359'],
            // Disclosed over-refusal: a month name read as a zone collides
            // with the date's own, and the parser warns.
            'a month name in front of an ISO date' => ['jan+2099-01-01'],
            // Numbers that are not unix seconds.
            'milliseconds' => ['4102358400000'],
            'exponent' => ['1e15'],
            'negative' => ['-1'],
            'decimal' => ['4102358400.5'],
            'far too many digits' => ['99999999999999999999999'],
            // Words for "no expiry" are not a date this method invents.
            'never' => ['never'],
            'unlimited' => ['unlimited'],
            // Dotted year-first: the parser returns no month and no day.
            'dotted year first' => ['2099.12.31'],
        ];
    }

    #[Test]
    #[DataProvider('expiriesThatNameNoDayTheyCanBeTrustedFor')]
    public function an_expiry_that_cannot_be_trusted_is_refused_not_guessed(string $encoded): void
    {
        $status = $this->licence('status=active&expires='.$encoded);

        $this->assertFalse($status->valid, 'An untrustworthy expiry licensed the node.');
        $this->assertSame('unconfirmed', $status->state);
        $this->assertNull($status->expiresAt);
    }

    // ------------------------------------------------------------------
    // Transformations the body passes through before any rule sees it
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesTheParserWouldRewrite(): array
    {
        return [
            'a repeated key' => ['status=expired&status=active&expires=2099-12-31'],
            'a percent-encoded alias of a key' => ['%73tatus=expired&status=active&expires=2099-12-31'],
            'a leading NUL erases a pair' => ['%00status=expired&status=active&expires=2099-12-31'],
            'a key the parser renames onto the expiry key' => ['expire.date=2020-01-01&status=active&expires=2099-12-31'],
            'a space renamed the same way' => ['expire+date=2020-01-01&status=active&expires=2099-12-31'],
            'a pair with no key' => ['=expired&status=active&expires=2099-12-31'],
            'a repeated error field' => ['%00error=1&text=License+expired&error=0&status=active'],
        ];
    }

    #[Test]
    #[DataProvider('bodiesTheParserWouldRewrite')]
    public function a_body_the_parser_would_rewrite_is_not_read(string $body): void
    {
        $status = $this->licence($body);

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
    }

    #[Test]
    public function a_body_longer_than_the_parser_will_keep_is_refused_without_a_warning(): void
    {
        $pairs = ['status=expired'];

        for ($i = 0; $i < (int) ini_get('max_input_vars'); $i++) {
            $pairs[] = 'filler'.$i.'=x';
        }

        // parse_str keeps the first max_input_vars pairs and warns; the
        // warning becomes an exception under the framework's handler, and the
        // truncation drops whatever comes last.
        $status = $this->licence(implode('&', [...$pairs, 'status=active']));

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
    }

    #[Test]
    public function an_error_field_that_is_not_a_number_is_not_a_success(): void
    {
        $status = $this->licence('error=none&status=active&expires=2099-12-31');

        $this->assertFalse($status->valid);
    }

    // ------------------------------------------------------------------
    // Who said the word "licence"
    // ------------------------------------------------------------------

    #[Test]
    public function an_outage_on_the_licence_command_is_not_read_as_a_lapsed_licence(): void
    {
        // The client's message carries the URL, and the URL carries
        // CMD_API_LICENSE — so matching "licen" against any exception message
        // records every outage as an invalid licence, and sends an operator to
        // the vendor about a network fault.
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 7: Failed to connect for https://node-b.lynomia.test:2222/CMD_API_LICENSE',
        ));

        $status = $this->provider()->licenceStatus($this->node());

        $this->assertFalse($status->valid);
        $this->assertSame('unconfirmed', $status->state);
    }

    #[Test]
    public function a_panel_that_itself_says_its_licence_lapsed_is_recorded_as_invalid(): void
    {
        $status = $this->licence('error=1&text=Your+license+has+expired');

        $this->assertFalse($status->valid);
        $this->assertSame('invalid', $status->state);
    }

    // ------------------------------------------------------------------
    // The other reads share the parser, and the same gates
    // ------------------------------------------------------------------

    #[Test]
    public function a_read_whose_body_names_nothing_the_command_returns_is_refused(): void
    {
        Http::fake(['*' => Http::response('foo=bar', 200)]);

        $this->expectException(HostingProviderException::class);

        $this->provider()->nodeHealth($this->node());
    }

    #[Test]
    public function a_leading_nul_cannot_hide_a_busy_nodes_load(): void
    {
        Http::fake(['*' => Http::response('%00loadavg1=9.9&loadavg1=0.1&version=1.665', 200)]);

        $this->expectException(HostingProviderException::class);

        $this->provider()->nodeHealth($this->node());
    }

    #[Test]
    public function a_leading_nul_cannot_hide_an_account_from_the_listing(): void
    {
        Http::fake(['*' => Http::response('%00list[]=alice&list[]=bob', 200)]);

        $this->expectException(HostingProviderException::class);

        $this->provider()->listAccounts($this->node());
    }

    private function licence(string $body): LicenceStatus
    {
        Http::fake(['*' => Http::response($body, 200)]);

        return $this->provider()->licenceStatus($this->node());
    }

    private function provider(): DirectAdminHostingProvider
    {
        return new DirectAdminHostingProvider(new SecretRedactor);
    }

    private function node(): HostingNode
    {
        config(['hosting.credentials.node-b' => ['username' => 'admin', 'login_key' => 'da-login-key-LICENCE-TEST']]);

        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X81',
            'slug' => 'node-b',
            'hostname' => 'node-b.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-b.lynomia.test:2222',
            'credentials_reference' => 'node-b',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }
}
