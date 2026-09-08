<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\HttpSiteProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one outbound request to an address a customer chose.
 *
 * ===========================================================================
 * WHY THIS TEST EXISTS
 * ===========================================================================
 *
 * Everything else this platform calls is a provider it configured. The site
 * probe fetches a name a customer typed, which resolves wherever they point
 * it — so without a guard it is a request-forgery primitive with a scheduler
 * attached, running every fifteen minutes, from inside the platform's own
 * network.
 *
 * The attack is not theoretical and it is not difficult: point
 * `mysite.example` at 169.254.169.254, order a WordPress site, and the
 * platform fetches its own cloud metadata service on a timer.
 *
 * These assert the refusal happens before any request is made — `Http::fake`
 * would record one if the guard let it through — and that the refusal covers
 * loopback, link-local and every private range rather than the one everybody
 * remembers.
 */
final class TheSiteProbeCannotBeAimedInwardsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function insideAddresses(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'loopback by name' => ['http://localhost/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private class A' => ['http://10.0.0.5/'],
            'private class B' => ['http://172.16.4.9/'],
            'private class C' => ['http://192.168.1.1/'],
            'IPv6 loopback' => ['http://[::1]/'],
        ];
    }

    #[Test]
    #[DataProvider('insideAddresses')]
    public function it_refuses_to_fetch_anything_inside_the_network(string $url): void
    {
        Http::fake();

        $result = (new HttpSiteProbe)->probe($url);

        $this->assertFalse($result->reachable);
        $this->assertNotNull($result->refusal);

        // The decisive assertion: nothing was sent. A guard that filtered the
        // response instead of the request would already have made the call.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_url_with_no_host_is_refused_rather_than_fetched(): void
    {
        Http::fake();

        $result = (new HttpSiteProbe)->probe('not a url at all');

        $this->assertFalse($result->reachable);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_name_that_does_not_resolve_is_refused_without_a_request(): void
    {
        Http::fake();

        // Nothing under .invalid resolves, by RFC 2606. A probe that fetched
        // it anyway would be relying on whatever the resolver decided to
        // return, which on some networks is a search-page hijack.
        $result = (new HttpSiteProbe)->probe('https://this-name-does-not-exist.invalid/');

        $this->assertFalse($result->reachable);
        Http::assertNothingSent();
    }
}
