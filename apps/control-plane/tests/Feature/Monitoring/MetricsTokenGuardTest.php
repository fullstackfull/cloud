<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Http\Middleware\MetricsTokenGuard;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The metrics endpoint is never public.
 *
 * Between them these series disclose how many customers the platform has and
 * what they buy, how much headroom is left before orders start failing, and
 * what the business earns. An unauthenticated GET would hand a competitor their
 * market research and an attacker their capacity plan — and unlike a breach,
 * serving it looks like a perfectly ordinary 200 in every log.
 */
final class MetricsTokenGuardTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'c8f0b1a2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(MonitoringServiceProvider::class);

        config()->set('monitoring.metrics.enabled', true);
        config()->set('monitoring.metrics.token', self::TOKEN);
        config()->set('monitoring.metrics.path', 'metrics');
        config()->set('monitoring.metrics.cache_seconds', 0);

        // The module owns its route definition; routes/** belongs to the
        // coordinator. Loading the real file here means this test covers the
        // wiring the coordinator is asked to install, not a reimplementation
        // of it.
        require base_path('src/Modules/Monitoring/Http/Routes/metrics.php');
    }

    #[Test]
    public function a_scrape_with_the_configured_token_is_served(): void
    {
        $response = $this->withToken(self::TOKEN)->get('/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', PrometheusTextFormatter::CONTENT_TYPE);
        $this->assertStringStartsWith('# HELP ', $response->getContent() ?: '');
    }

    #[Test]
    public function a_request_with_no_authorization_header_is_refused(): void
    {
        $this->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function a_request_with_an_empty_bearer_token_is_refused(): void
    {
        $this->withHeader('Authorization', 'Bearer ')->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function a_request_with_the_wrong_token_is_refused(): void
    {
        $this->withToken('completely-wrong')->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function a_token_that_is_a_prefix_of_the_real_one_is_refused(): void
    {
        // The shape an attacker produces while probing a comparison that stops
        // at the first differing byte.
        $this->withToken(substr(self::TOKEN, 0, -1))->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function a_token_of_the_right_length_differing_in_one_byte_is_refused(): void
    {
        $nearMiss = substr(self::TOKEN, 0, -1).(str_ends_with(self::TOKEN, 'f') ? 'e' : 'f');

        $this->assertSame(strlen(self::TOKEN), strlen($nearMiss));
        $this->withToken($nearMiss)->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function a_token_longer_than_the_real_one_is_refused(): void
    {
        $this->withToken(self::TOKEN.'extra')->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function an_unconfigured_token_closes_the_endpoint_rather_than_opening_it(): void
    {
        /*
         * The failure mode being designed out: a fresh deployment where nobody
         * has set PROMETHEUS_METRICS_TOKEN yet, publishing revenue and customer
         * counts to the internet, silently, because "no token configured" was
         * read as "no authentication required".
         */
        config()->set('monitoring.metrics.token', null);

        $this->withToken(self::TOKEN)->get('/metrics')->assertNotFound();
        $this->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function an_empty_configured_token_closes_the_endpoint(): void
    {
        // An unset env var arrives as '' rather than null often enough that it
        // needs its own case.
        config()->set('monitoring.metrics.token', '');

        $this->withHeader('Authorization', 'Bearer ')->get('/metrics')->assertNotFound();
        $this->withToken('')->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function the_endpoint_can_be_switched_off_entirely(): void
    {
        config()->set('monitoring.metrics.enabled', false);

        $this->withToken(self::TOKEN)->get('/metrics')->assertNotFound();
    }

    #[Test]
    public function refusal_is_a_404_so_the_endpoint_does_not_confirm_its_own_existence(): void
    {
        // 401 tells a scanner there is something here worth coming back for.
        // The operator's signal is the warning the guard logs, which is where
        // they would be looking anyway.
        $response = $this->withToken('wrong')->get('/metrics');

        $response->assertNotFound();
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }

    #[Test]
    public function a_refused_request_never_reaches_the_collectors(): void
    {
        // Not just a wasted query: an unauthenticated caller must not be able
        // to make the platform run its most expensive read path at will.
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->withToken('wrong')->get('/metrics')->assertNotFound();

        $this->assertSame(0, $queries);
    }

    #[Test]
    public function the_token_comparison_is_timing_safe(): void
    {
        /*
         * Behaviour cannot demonstrate constant time in a test, so the
         * structure is asserted instead. That is not a formality: the natural
         * way to write this line is `$presented === $expected`, which returns
         * as soon as two bytes differ and leaks the shared prefix one request
         * at a time.
         *
         * The digests are the second half of it. hash_equals is constant time
         * only across equal-length inputs; given different lengths it returns
         * immediately and leaks the real token's length. Hashing both to a
         * fixed width first removes that.
         */
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(MetricsTokenGuard::class))->getFileName()
        );

        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertStringContainsString("hash('sha256', \$expected)", $source);
        $this->assertStringContainsString("hash('sha256', \$presented)", $source);

        $this->assertDoesNotMatchRegularExpression(
            '/\$(presented|expected)\s*(===|==|!==|!=)\s*\$(presented|expected)/',
            $source,
            'The token must never be compared with a short-circuiting operator.',
        );

        $this->assertStringNotContainsString('strcmp(', $source);
    }

    #[Test]
    public function the_response_is_never_cached_by_anything_in_between(): void
    {
        // A proxy holding a copy would serve one host's numbers for another's,
        // and would keep serving them after that host stopped answering — the
        // one moment the scrape has to fail rather than succeed.
        $response = $this->withToken(self::TOKEN)->get('/metrics');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
