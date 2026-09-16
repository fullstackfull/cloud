<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The negative matrix: every impostor, against every HTTPS driver.
 *
 * ===========================================================================
 * WHAT THIS IS FOR
 * ===========================================================================
 *
 * Phase 30B.0 went looking for the real estate and found that this project's
 * sandbox reaches the internet through an egress gateway which answers a TLS
 * handshake for **any** hostname — `this-host-does-not-exist-9f3a2b.invalid`
 * included — and presents a certificate that verifies, `Verify return code: 0
 * (ok)`, from a CA the host trusts.
 *
 * So the three things a connection test is most tempted to treat as success
 * are worthless as evidence: a TCP connection was accepted, a TLS handshake
 * completed, the certificate verified. Only the application layer
 * discriminates.
 *
 * A comment in a base class saying so is not protection. This is: a table of
 * responses an impostor plausibly gives — an empty body, an empty JSON object,
 * a login page, a load balancer's "OK", a bare 401, a bare 403, a redirect,
 * and **each real product's answer served to every other product's driver** —
 * run against every registered HTTPS tester, asserting each time that the
 * platform reports {@see ConnectionState::IdentityMismatch} and never anything
 * {@see ConnectionState::usable()} answers true for.
 *
 * ===========================================================================
 * WHY IT IS DRIVEN BY THE REGISTRY
 * ===========================================================================
 *
 * The driver list comes from the tester factory, not from a list written here.
 * A tester added later inherits this whole matrix on the day it is registered,
 * without anybody remembering to extend a test — which is the only way a gate
 * like this stays true a year from now.
 */
final class AConnectionIsNotHealthyBecauseASocketOpenedTest extends TestCase
{
    /**
     * A target for each HTTPS driver: an endpoint of the shape that driver
     * takes, and a credential of the shape it parses.
     *
     * The credentials are structurally valid and belong to nothing. They exist
     * so that the test reaches the network layer rather than stopping at the
     * credential check — a test that never got past parsing would pass this
     * whole file for the wrong reason.
     *
     * @var array<string, array{endpoint: ?string, secret: string, category: ProviderCategory, family: string}>
     */
    private const array TARGETS = [
        'proxmox' => [
            'endpoint' => 'https://pve.example.test:8006',
            'secret' => 'lynomia@pve!control=00000000-0000-0000-0000-000000000000',
            'category' => ProviderCategory::Compute,
            'family' => 'proxmox',
        ],
        'proxmox_backup' => [
            'endpoint' => 'https://pve.example.test:8006',
            'secret' => 'lynomia@pve!control=00000000-0000-0000-0000-000000000000',
            'category' => ProviderCategory::Backup,
            'family' => 'proxmox',
        ],
        'cpanel' => [
            'endpoint' => 'https://whm.example.test:2087',
            'secret' => 'lynomia:0000000000000000000000000000000',
            'category' => ProviderCategory::Hosting,
            'family' => 'cpanel',
        ],
        'directadmin' => [
            'endpoint' => 'https://da.example.test:2222',
            'secret' => 'lynomia:0000000000000000000000000000000',
            'category' => ProviderCategory::Hosting,
            'family' => 'directadmin',
        ],
        'cloudflare' => [
            'endpoint' => null,
            'secret' => '0000000000000000000000000000000000000000',
            'category' => ProviderCategory::Dns,
            'family' => 'cloudflare',
        ],
        'cloudflare_rdns' => [
            'endpoint' => null,
            'secret' => '0000000000000000000000000000000000000000',
            'category' => ProviderCategory::ReverseDns,
            'family' => 'cloudflare',
        ],
        'redfish' => [
            'endpoint' => '10.40.0.11',
            'secret' => 'lynomia:0000000000000000',
            'category' => ProviderCategory::Bmc,
            'family' => 'redfish',
        ],
        'ilo' => [
            'endpoint' => '10.40.0.12',
            'secret' => 'lynomia:0000000000000000',
            'category' => ProviderCategory::Bmc,
            'family' => 'redfish',
        ],
    ];

    /**
     * What an impostor answers with.
     *
     * `family` names the product an answer legitimately belongs to, so that a
     * product's own answer is not served to its own driver and counted as a
     * failure. Everything with a null family belongs to nobody and must be
     * refused by everybody.
     *
     * @var array<string, array{body: string, status: int, headers: array<string, string>, family: ?string}>
     */
    private const array IMPOSTORS = [
        'an empty body' => ['body' => '', 'status' => 200, 'headers' => [], 'family' => null],
        'an empty JSON object' => ['body' => '{}', 'status' => 200, 'headers' => [], 'family' => null],
        'a login page' => [
            'body' => '<!doctype html><html><head><title>Sign in</title></head><body><form>Username</form></body></html>',
            'status' => 200, 'headers' => [], 'family' => null,
        ],
        'a load balancer saying OK' => ['body' => 'OK', 'status' => 200, 'headers' => [], 'family' => null],
        'plain text' => ['body' => 'service temporarily unavailable', 'status' => 200, 'headers' => [], 'family' => null],
        'a bare 401' => ['body' => '', 'status' => 401, 'headers' => [], 'family' => null],
        'a bare 403 with a JSON error' => ['body' => '{"error":"unauthorized"}', 'status' => 403, 'headers' => [], 'family' => null],
        'a redirect to somewhere else' => [
            'body' => '', 'status' => 302, 'headers' => ['Location' => 'https://elsewhere.example.test/login'], 'family' => null,
        ],
        'the Proxmox API answer' => [
            'body' => '{"data":{"version":"8.2.4","release":"8.2","repoid":"abcdef"}}',
            'status' => 200, 'headers' => [], 'family' => 'proxmox',
        ],
        'the Cloudflare API answer' => [
            'body' => '{"success":true,"errors":[],"messages":[],"result":{"status":"active"}}',
            'status' => 200, 'headers' => [], 'family' => 'cloudflare',
        ],
        'the WHM API answer' => [
            'body' => '{"metadata":{"command":"version","result":1,"reason":"OK"},"data":{"version":"11.126.0.4"}}',
            'status' => 200, 'headers' => [], 'family' => 'cpanel',
        ],
        'a Redfish service root' => [
            'body' => '{"@odata.id":"/redfish/v1/","RedfishVersion":"1.6.0","Systems":{"@odata.id":"/redfish/v1/Systems"}}',
            'status' => 200, 'headers' => [], 'family' => 'redfish',
        ],
        'a DirectAdmin answer' => [
            'body' => 'kernel=5.15.0&os=linux&loadavg=0.14&hostname=node',
            'status' => 200, 'headers' => [], 'family' => 'directadmin',
        ],
    ];

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function impostors(): iterable
    {
        foreach (self::TARGETS as $driver => $target) {
            foreach (self::IMPOSTORS as $name => $impostor) {
                if ($impostor['family'] === $target['family']) {
                    // A product's own answer, given to its own driver. Not an
                    // impostor, and asserting a mismatch for it would be
                    // asserting the tester does not work.
                    continue;
                }

                yield $driver.' refuses '.$name => [$driver, $name];
            }
        }
    }

    #[Test]
    #[DataProvider('impostors')]
    public function a_driver_refuses_an_answer_that_is_not_its_own_product(string $driver, string $impostorName): void
    {
        $impostor = self::IMPOSTORS[$impostorName];
        $target = self::TARGETS[$driver];

        Http::fake(['*' => Http::response($impostor['body'], $impostor['status'], $impostor['headers'])]);

        $result = app(ConnectionTesterFactory::class)->for($driver)->test(new TestTarget(
            driver: $driver,
            environment: DeploymentEnvironment::Staging,
            endpoint: $target['endpoint'],
            secret: $target['secret'],
            probeCapabilities: $target['category']->capabilities(),
            identity: 'a row under test',
        ));

        $this->assertFalse(
            $result->state->usable(),
            "The {$driver} tester reported {$result->state->value} — a usable state — for {$impostorName}. "
            .'A socket opening is not a healthy connection, and this is the whole reason this file exists.',
        );

        $this->assertSame(
            ConnectionState::IdentityMismatch,
            $result->state,
            "The {$driver} tester answered {$impostorName} with {$result->state->value}. It should be an identity "
            .'mismatch: nothing established what is at that address, so nothing may be concluded about the '
            .'credential, the licence or the permissions either.',
        );

        /*
         * And not AuthFailed in particular, for the two impostors most likely
         * to be mistaken for one. A 401 from an unidentified host sends an
         * operator to rotate a credential that is fine, and they lose the
         * afternoon and their trust in the credential centre with it.
         */
        $this->assertNotSame(ConnectionState::AuthFailed, $result->state);
    }

    #[Test]
    public function every_https_driver_in_the_registry_is_covered_by_this_matrix(): void
    {
        /*
         * The gate on the gate. A tester registered without a target here
         * would be silently exempt from every assertion above, and this file
         * would go on passing while covering less than it did yesterday.
         */
        $registered = app(ConnectionTesterFactory::class)->drivers();

        $httpDrivers = array_values(array_diff($registered, ['fake', 'fake_bmc', 'stripe', 'ipmi']));

        $this->assertSame(
            [],
            array_values(array_diff($httpDrivers, array_keys(self::TARGETS))),
            'These HTTPS drivers are registered and are not in this file\'s target table, so none of the impostors '
            .'above is ever served to them. Add a target: an endpoint of the shape the driver takes and a credential '
            .'of the shape it parses.',
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::TARGETS), $registered)),
            'These drivers are in this file\'s target table and are not registered, so the assertions about them '
            .'prove nothing about the running platform.',
        );
    }

    #[Test]
    public function a_verified_certificate_for_a_hostname_that_does_not_exist_proves_nothing(): void
    {
        /*
         * The finding, restated as an executable case.
         *
         * The gateway in this project's sandbox completes a verified handshake
         * for a hostname nobody registered and then answers at the HTTP layer
         * with something that is not the product. Faked here as a 200 with the
         * gateway's own shape rather than dialled, because dialling real
         * infrastructure from a test suite is the thing this platform does not
         * do — and because the assertion is about what the platform concludes,
         * which does not depend on who produced the bytes.
         */
        Http::fake(['*' => Http::response('{"message":"upstream connect error or disconnect"}', 200)]);

        $result = app(ConnectionTesterFactory::class)->for('proxmox')->test(new TestTarget(
            driver: 'proxmox',
            environment: DeploymentEnvironment::Staging,
            endpoint: 'https://this-host-does-not-exist-9f3a2b.invalid',
            secret: 'lynomia@pve!control=00000000-0000-0000-0000-000000000000',
            probeCapabilities: ProviderCategory::Compute->capabilities(),
            identity: 'a cluster that is not there',
        ));

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);

        $names = array_map(
            static fn (array $step): string => $step['name'],
            $result->stepsAsArray(),
        );

        /*
         * The step list is the operator's account of what happened, and the
         * order in it is the argument: the handshake passed, and the identity
         * did not. An operator reading a green TLS row and a red identity row
         * has been told the truth in the right order.
         */
        $this->assertSame(['credential', 'https', 'identity'], $names);

        $steps = $result->stepsAsArray();

        $this->assertSame('passed', $steps[1]['outcome']);
        $this->assertStringContainsString('not yet evidence', (string) $steps[1]['detail']);
        $this->assertSame('failed', $steps[2]['outcome']);
    }
}
