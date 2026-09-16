<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Testers\FakeConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\HttpIdentityTester;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No catalogued driver may be untestable by accident.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO PREVENT
 * ===========================================================================
 *
 * Until this phase, `Infrastructure/Testers/` held exactly one file: the fake.
 * Every real driver in the catalogue — Proxmox, the backup path, cPanel,
 * DirectAdmin, Cloudflare, Stripe, Redfish, iLO, IPMI — had an adapter that
 * could provision a customer's machine and nothing that could establish the
 * account it would provision it through was even reachable.
 *
 * The readiness engine handled that honestly: a driver with no tester is a
 * configuration blocker, and it said so. But "handled honestly" and "closed"
 * are different words, and the gap was invisible in the place it mattered —
 * nothing failed, nothing warned, and the catalogue grew.
 *
 * So the rule is now written down and enforced: a catalogued driver either has
 * a tester, or appears below with the reason it cannot have one. Adding a
 * driver to the catalogue fails this test until somebody does one or the
 * other.
 *
 * ===========================================================================
 * THE SECOND HALF
 * ===========================================================================
 *
 * A tester existing is not enough. It must be the *right* tester, and it must
 * not be the fake — a real driver resolving to a tester that reports Connected
 * for a machine nobody has bought is worse than a real driver with no tester
 * at all, because the first is a lie and the second is a gap.
 */
final class EveryRealDriverHasAnIdentityTesterTest extends TestCase
{
    /**
     * Catalogued drivers with no connection tester, and why.
     *
     * Two, and neither is an oversight. Each reason is a statement about the
     * world rather than about this codebase, which is what makes them
     * allowable rather than a to-do list.
     *
     * @var array<string, string>
     */
    private const array UNTESTABLE = [
        'smtp' => 'There is no endpoint to test. The relay is a deployment setting — MAIL_HOST — reached over SMTP '
            .'by the framework\'s own mailer, and in some deployments it is the log driver. A tester would have to '
            .'send a message to establish anything, which is a write, and it would establish it about the mailer '
            .'rather than about a provider row. The catalogue row exists so readiness has something to point at, '
            .'and it stays blocked on credentials.',
        'sy_registry' => 'The .sy registry\'s technical contract is not available to this project. Its adapter is a '
            .'deliberate placeholder whose every method refuses, so there is no endpoint, no authentication '
            .'mechanism and no documented response shape to identify. A tester written from guesses would be '
            .'fiction, and the one thing worse than no registrar is a registrar the platform believes in.',
    ];

    #[Test]
    public function every_catalogued_driver_either_has_a_tester_or_a_recorded_reason(): void
    {
        $factory = app(ConnectionTesterFactory::class);
        $catalogue = new ProviderCatalogue;

        $missing = [];

        foreach ($catalogue->entries() as $entry) {
            if ($factory->handles($entry->driver) || array_key_exists($entry->driver, self::UNTESTABLE)) {
                continue;
            }

            $missing[] = $entry->driver;
        }

        $this->assertSame(
            [],
            $missing,
            'These drivers are catalogued, so the platform claims to support them, and nothing can establish that an '
            ."instance of one is reachable:\n  ".implode("\n  ", $missing)
            ."\n\nWrite a tester that identifies the product from something only that product says, or add the driver "
            .'to UNTESTABLE with the reason it cannot have one.',
        );
    }

    #[Test]
    public function no_driver_is_excused_without_a_reason_that_is_still_true(): void
    {
        $factory = app(ConnectionTesterFactory::class);
        $catalogue = new ProviderCatalogue;

        foreach (self::UNTESTABLE as $driver => $reason) {
            $this->assertNotNull(
                $catalogue->find($driver),
                "{$driver} is excused from having a tester and is no longer in the catalogue. Delete the excuse.",
            );

            $this->assertFalse(
                $factory->handles($driver),
                "{$driver} is excused from having a tester and now has one. Delete the excuse: an allow-list entry "
                .'that is no longer true is how the next real gap gets excused too.',
            );

            $this->assertGreaterThan(
                120,
                mb_strlen($reason),
                "The reason {$driver} has no tester is too short to be a reason. It has to say what about the world "
                .'makes a tester impossible, so that the next person can tell whether it still does.',
            );
        }
    }

    #[Test]
    public function every_registered_tester_answers_for_the_driver_it_is_registered_under(): void
    {
        /*
         * The factory checks this when it builds one, which protects a
         * request. This checks every registration at once, which protects the
         * registry — a tester filed under the wrong key would test the wrong
         * product and report a confident answer about it, and the first person
         * to find out would be a customer.
         */
        $factory = app(ConnectionTesterFactory::class);

        foreach ($factory->drivers() as $driver) {
            $tester = $factory->for($driver);

            $this->assertSame($driver, $tester->driver());
            $this->assertInstanceOf(ConnectionTester::class, $tester);
        }
    }

    #[Test]
    public function no_real_driver_resolves_to_the_fake_tester(): void
    {
        $factory = app(ConnectionTesterFactory::class);
        $controlled = (new ProviderCatalogue)->controlledDrivers();

        foreach ($factory->drivers() as $driver) {
            if (in_array($driver, $controlled, true)) {
                continue;
            }

            $this->assertNotInstanceOf(
                FakeConnectionTester::class,
                $factory->for($driver),
                "The {$driver} driver resolves to the fake tester. A real driver answered by a fake reports machines "
                .'nobody has bought as connected, and the readiness engine sells products on the strength of it.',
            );
        }
    }

    #[Test]
    public function every_http_tester_goes_through_the_one_request_builder(): void
    {
        /*
         * The false-positive protection is structural — see
         * {@see HttpIdentityTester} — and it is structural only for testers
         * that inherit it. A tester that built its own Illuminate request
         * would get to choose whether to verify the certificate, whether to
         * follow redirects, and whether to ask anything at all before
         * reporting Connected.
         *
         * So the two testers that do not inherit it are named, with the reason
         * each cannot: both speak something other than HTTP, and neither has
         * the false-positive problem the base class exists to solve.
         */
        $exempt = [
            'stripe' => 'Speaks to Stripe through the official SDK, which owns its own client and error hierarchy. A '
                .'tester that opened its own HTTP connection would prove something about a code path the platform '
                .'never takes.',
            'ipmi' => 'Speaks IPMI 2.0 over LAN through ipmitool, over UDP. There is no TCP connection to accept and '
                .'no certificate to impersonate, so an established RMCP+ session is itself proof of both identity '
                .'and credential.',
        ];

        $factory = app(ConnectionTesterFactory::class);
        $controlled = (new ProviderCatalogue)->controlledDrivers();

        foreach ($factory->drivers() as $driver) {
            if (in_array($driver, $controlled, true) || array_key_exists($driver, $exempt)) {
                continue;
            }

            $this->assertInstanceOf(
                HttpIdentityTester::class,
                $factory->for($driver),
                "The {$driver} tester does not inherit HttpIdentityTester, so it is not covered by the rule that "
                .'nothing usable may be reported without a matched identity proof. Inherit it, or add the driver to '
                .'the exemptions above with the reason it speaks something else.',
            );
        }

        foreach (array_keys($exempt) as $driver) {
            $this->assertTrue($factory->handles($driver), "{$driver} is exempt from the base class and is not registered at all.");
        }
    }
}
