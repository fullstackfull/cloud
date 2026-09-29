<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Dns\Application\Actions\ClaimZone;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\ReservedZonesCheck;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * On production, the reserved-zones state the preflight blocks on is a
 * refusal, not only a report (F-26).
 *
 * The estate preflight said `blocked` for a production deployment that
 * reserved nothing, or whose own host had claimable names beside it — and
 * nothing consumed that verdict: `infra:preflight` is not part of a deploy,
 * and ClaimZone never asked. A production estate on the shipped empty
 * `DNS_RESERVED_ZONES` accepted `www.` and `mail.` beside its own control
 * plane from any account.
 *
 * Now, in production, while the state would be blocked every claim is
 * refused with the platform-condition refusal — `dns.zone.unavailable`, 503,
 * disclosing nothing — and the operator is told at error level. Anywhere
 * else nothing changes: a rehearsal is warned by the preflight and not
 * stopped.
 */
final class AProductionEstateThatHoldsTooLittleClaimsNothingTest extends DnsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Resolved before the environment changes: the simulator refuses to
        // be built in production, and this is about the guard, not about it.
        $this->provider();
    }

    /**
     * @param  list<string>  $listed
     */
    #[Test]
    #[DataProvider('estates')]
    public function the_guard_refuses_on_production_exactly_where_the_preflight_blocks(
        array $listed,
        string $app,
        string $portal,
        bool $blocked,
    ): void {
        config()->set('dns.reserved_zones', $listed);
        config()->set('app.url', $app);
        config()->set('app.frontend_url', $portal);

        $finding = app(ReservedZonesCheck::class)->inspect(true)[0];
        $this->assertSame($blocked, $finding->status === CheckStatus::Blocked, 'The case table disagrees with the preflight: '.$finding->summary);

        $this->app->detectEnvironment(static fn (): string => 'production');
        Log::spy();

        $refusal = $this->claim('an-unrelated-customer-domain.test');

        if (! $blocked) {
            $this->assertNull($refusal, 'A production estate that holds its names takes claims.');
            $this->assertSame(1, DnsZone::query()->count());
            Log::shouldNotHaveReceived('error');

            return;
        }

        $this->assertInstanceOf(DnsRefusedException::class, $refusal);
        $this->assertSame('dns.zone.unavailable', $refusal->errorCode());
        $this->assertSame(503, $refusal->httpStatus());
        $this->assertSame('New zones cannot be added right now. Try again later.', $refusal->getMessage());
        $this->assertSame([], $refusal->context(), 'Nothing about the configuration reaches the claimant.');
        $this->assertSame(0, DnsZone::query()->count());

        Log::shouldHaveReceived('error')->withArgs(static function (string $message, array $context) use ($app, $portal): bool {
            $said = $message.json_encode($context, JSON_THROW_ON_ERROR);

            return str_contains($message, 'DNS_RESERVED_ZONES')
                && ($context['preflight_finding'] ?? null) === 'dns.reserved_zones'
                // Counts and variables, never a name or an address.
                && ! str_contains($said, (string) parse_url($app, PHP_URL_HOST))
                && ! str_contains($said, (string) parse_url($portal, PHP_URL_HOST));
        })->once();
    }

    #[Test]
    public function names_beside_the_control_plane_are_refused_on_a_production_estate_that_holds_only_its_hosts_by_derivation(): void
    {
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', 'https://api.example.net');
        config()->set('app.frontend_url', 'https://portal.example.net');

        $this->app->detectEnvironment(static fn (): string => 'production');

        foreach (['www.example.net', 'mail.example.net'] as $name) {
            $refusal = $this->claim($name);
            $this->assertInstanceOf(DnsRefusedException::class, $refusal, $name.' was claimed beside the control plane.');
            $this->assertSame('dns.zone.unavailable', $refusal->errorCode());
        }

        $this->assertSame(0, DnsZone::query()->count());
    }

    #[Test]
    public function outside_production_the_same_estate_still_takes_claims(): void
    {
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', 'https://api.example.net');
        config()->set('app.frontend_url', 'https://portal.example.net');

        $this->assertFalse($this->app->isProduction());

        $this->assertNull($this->claim('www.example.net'), 'A rehearsal is warned by the preflight, not stopped.');
        $this->assertSame(CheckStatus::Warning, app(ReservedZonesCheck::class)->inspect(false)[0]->status);
    }

    #[Test]
    public function a_name_the_estate_does_hold_is_still_answered_as_reserved_on_production(): void
    {
        config()->set('dns.reserved_zones', ['example.net']);
        config()->set('app.url', 'https://api.example.net');
        config()->set('app.frontend_url', 'https://portal.example.net');

        $this->app->detectEnvironment(static fn (): string => 'production');

        $refusal = $this->claim('www.example.net');
        $this->assertInstanceOf(DnsRefusedException::class, $refusal);
        $this->assertSame('dns.zone.reserved', $refusal->errorCode());
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2: string, 3: bool}>
     */
    public static function estates(): iterable
    {
        yield 'the shipped configuration: nothing listed, nothing derived' => [[], 'http://localhost:8000', 'http://localhost:5173', true];
        yield 'nothing listed, hosts derived with claimable names beside them' => [[], 'https://api.example.net', 'https://portal.example.net', true];
        yield 'one host held from above, the other claimable beside it' => [[], 'https://example.net', 'https://portal.other.net', true];
        yield 'the registrable domain listed' => [['example.net'], 'https://api.example.net', 'https://portal.example.net', false];
        yield 'each host listed exactly (a warning, not a block)' => [['api.example.net', 'portal.example.net'], 'https://api.example.net', 'https://portal.example.net', false];
        yield 'hosts of two labels' => [[], 'https://example.net', 'https://example.org', false];
    }

    private function claim(string $name): ?DnsRefusedException
    {
        try {
            app(ClaimZone::class)->execute(Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']), $name);
        } catch (DnsRefusedException $e) {
            return $e;
        }

        return null;
    }
}
