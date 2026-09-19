<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Domain\Services\ProviderReadiness;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Production may not be pointed at the reference estate.
 *
 * ===========================================================================
 * THE DEFECT THIS FILE WAS WRITTEN AFTER MEASURING
 * ===========================================================================
 *
 * `EndpointPolicy` carried a comment saying that the reserved ranges it
 * refuses include "documentation". It did not. The flag it relies on,
 * FILTER_FLAG_NO_RES_RANGE, covers loopback, link-local, the unspecified
 * address and 240/4 — and, measured on PHP 8.4, accepts every one of
 * 192.0.2.10, 198.51.100.10, 203.0.113.10 and 2001:db8::1, with and without
 * NO_PRIV_RANGE.
 *
 * So before Gap 4, every documentation address in this repository's own
 * examples — the Ansible production inventory is entirely 203.0.113/24 — was
 * an acceptable production provider endpoint, and the comment said the
 * opposite. That is the worst shape a defect can have: a guard that is
 * believed to exist.
 *
 * ===========================================================================
 * WHY EACH REFUSAL IS TWINNED
 * ===========================================================================
 *
 * Refusing documentation addresses is easy to get wrong in the direction of
 * refusing too much: a substring check for "203.0.113." would also reject
 * 203.0.113.0/24's neighbours in a CIDR string, and a substring check for
 * "example" would reject `example-hosting.net`, which is somebody's real
 * domain. Every case below therefore has a companion asserting that a
 * legitimate production value is accepted, and the classifier's own tests
 * include the near-misses on purpose.
 */
final class ProductionRefusesTheReferenceEstateTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): EndpointPolicy
    {
        return new EndpointPolicy;
    }

    // ---------------------------------------------------------------------
    // Documentation addresses (§37)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function documentationAddresses(): array
    {
        return [
            'RFC 5737 TEST-NET-1' => ['https://192.0.2.10'],
            'RFC 5737 TEST-NET-2' => ['https://198.51.100.10'],
            'RFC 5737 TEST-NET-3' => ['https://203.0.113.11'],
            'RFC 5737 range edge, first' => ['https://203.0.113.0'],
            'RFC 5737 range edge, last' => ['https://203.0.113.255'],
            'RFC 3849' => ['https://[2001:db8::1]'],
            'RFC 3849, deep in the prefix' => ['https://[2001:db8:100:abcd::5]'],
            // 3fff::/20 is 3fff:0000:: through 3fff:0fff:ffff:…, so the second
            // group has to start with a zero nibble to be inside it. The first
            // version of this case used 3fff:abcd::1, which is outside — the
            // classifier was right and the test data was wrong.
            'RFC 9637' => ['https://[3fff::1]'],
            'RFC 9637, the top of the block' => ['https://[3fff:0fff:ffff::1]'],
        ];
    }

    #[Test]
    #[DataProvider('documentationAddresses')]
    public function a_documentation_address_is_refused_as_a_production_endpoint(string $endpoint): void
    {
        $this->expectException(EndpointRefused::class);
        $this->expectExceptionMessageMatches('/reserved for documentation/');

        $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: true);
    }

    #[Test]
    #[DataProvider('documentationAddresses')]
    public function the_same_address_is_accepted_outside_production(string $endpoint): void
    {
        // The positive twin for the whole family. These values are correct in
        // the reference topology, in the example inventories and in fixtures,
        // and a guard that rejected them everywhere would make the reference
        // estate unloadable.
        $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: false);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realAddresses(): array
    {
        return [
            'a public address one octet outside TEST-NET-3' => ['https://203.0.114.11'],
            'a public address one octet below TEST-NET-3' => ['https://203.0.112.11'],
            'a public address outside TEST-NET-1' => ['https://192.0.3.10'],
            'a public address outside TEST-NET-2' => ['https://198.51.101.10'],
            'a v6 address outside the documentation prefix' => ['https://[2001:db9::1]'],
            'a v6 address one prefix below' => ['https://[2001:db7::1]'],
            'a v6 address outside RFC 9637' => ['https://[4fff::1]'],
            'a v6 address just past the RFC 9637 block' => ['https://[3fff:1000::1]'],
            'a private management address on our own hardware' => ['https://10.20.30.40'],
        ];
    }

    #[Test]
    #[DataProvider('realAddresses')]
    public function a_real_address_is_accepted_in_production(string $endpoint): void
    {
        $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: true);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_documentation_address_is_refused_as_a_production_machine_address(): void
    {
        $this->expectException(EndpointRefused::class);
        $this->expectExceptionMessageMatches('/reserved for documentation/');

        $this->policy()->assertMachineAddress('192.0.2.111', production: true);
    }

    #[Test]
    public function a_documentation_address_with_a_port_is_refused_too(): void
    {
        // The port-stripping path is separate from the literal path, and a
        // guard that only covered one of them would be defeated by ":443".
        $this->expectException(EndpointRefused::class);
        $this->expectExceptionMessageMatches('/reserved for documentation/');

        $this->policy()->assertMachineAddress('192.0.2.130:443', production: true);
    }

    #[Test]
    public function a_real_machine_address_is_accepted_in_production(): void
    {
        $this->policy()->assertMachineAddress('10.20.30.41:443', production: true);

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // Reserved domains (§38)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function reservedHostnames(): array
    {
        return [
            'the Ansible production inventory naming' => ['https://cp-1.prod.example'],
            'a reference hosting node' => ['https://ref-hosting-alpha-1.reference.example'],
            'the bare reserved TLD' => ['https://something.example'],
            'RFC 2606 second level' => ['https://api.example.com'],
            'the development seeder naming' => ['https://shared-kw-1.lynomia.test'],
            "Gap 2's negative matrix naming" => ['https://this-host-does-not-exist-9f3a2b.invalid'],
        ];
    }

    #[Test]
    #[DataProvider('reservedHostnames')]
    public function a_name_under_a_reserved_domain_is_refused_in_production(string $endpoint): void
    {
        $this->expectException(EndpointRefused::class);
        $this->expectExceptionMessageMatches('/never delegated/');

        $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realHostnames(): array
    {
        return [
            // The cases a substring check gets wrong. All three are names
            // somebody could really own.
            'a real domain containing the word' => ['example-hosting.net'],
            'a real domain whose TLD starts with the word' => ['a.example.community'],
            'a real domain whose label ends with the word' => ['notexample.com'],
            'a real domain whose label ends with test' => ['api.latest.dev'],
            'an ordinary provider' => ['api.cloudflare.com'],
        ];
    }

    #[Test]
    #[DataProvider('realHostnames')]
    public function a_real_name_is_not_mistaken_for_a_reserved_one(string $host): void
    {
        self::assertFalse(
            (new ReferenceValues)->isDocumentationHostname($host),
            $host.' was classified as a documentation name. It is a name somebody could own.',
        );
    }

    #[Test]
    public function a_reserved_name_is_still_accepted_outside_production(): void
    {
        $this->policy()->assertProviderEndpoint(
            'https://cp-1.prod.example',
            controlledDriver: false,
            onOurHardware: true,
            production: false,
        );

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------
    // The activation path (§7, §36)
    // ---------------------------------------------------------------------

    #[Test]
    public function a_provider_row_declared_for_production_with_a_reference_endpoint_is_not_ready(): void
    {
        $provider = $this->productionProvider('https://203.0.113.11');

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $provider,
            testerAvailable: true,
        );

        self::assertFalse($verdict->isReady());
        self::assertSame(BlockerReason::Configuration, $verdict->blocker);
        self::assertStringContainsString('declared for production', $verdict->detail);
        self::assertStringContainsString('reserved for documentation', $verdict->detail);
    }

    #[Test]
    public function a_provider_row_declared_for_production_with_an_example_hostname_is_not_ready(): void
    {
        $provider = $this->productionProvider('https://dns-1.prod.example');

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $provider,
            testerAvailable: true,
        );

        self::assertFalse($verdict->isReady());
        self::assertSame(BlockerReason::Configuration, $verdict->blocker);
        self::assertStringContainsString('never delegated', $verdict->detail);
    }

    #[Test]
    public function a_provider_row_declared_for_production_with_a_reference_identifier_is_not_ready(): void
    {
        $provider = $this->productionProvider('ref-provider-alpha-dns');

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $provider,
            testerAvailable: true,
        );

        self::assertFalse($verdict->isReady());
        self::assertStringContainsString('reference topology', $verdict->detail);
    }

    /**
     * The twin that makes the three above mean something: the identical row
     * with a real endpoint gets past this check.
     *
     * It does not become Ready — nothing has contacted it and it has no
     * discovered capabilities, and that refusal belongs to the rest of the
     * readiness ladder. What matters here is that the blocker is no longer the
     * reference one.
     */
    #[Test]
    public function the_same_row_with_a_real_endpoint_is_not_blocked_on_the_reference_rule(): void
    {
        $provider = $this->productionProvider('https://api.cloudflare.com');

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $provider,
            testerAvailable: true,
        );

        self::assertStringNotContainsString('declared for production', $verdict->detail ?? '');
        self::assertStringNotContainsString('reserved for documentation', $verdict->detail ?? '');
    }

    /**
     * A row declared for development may carry reference values, and must,
     * because that is what the reference estate is.
     */
    #[Test]
    public function a_development_row_with_a_reference_endpoint_is_not_blocked_on_the_reference_rule(): void
    {
        $provider = $this->productionProvider('https://203.0.113.11');
        $provider->environment = DeploymentEnvironment::Development;

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $provider,
            testerAvailable: true,
        );

        self::assertStringNotContainsString('reserved for documentation', $verdict->detail ?? '');
    }

    /**
     * A production row whose endpoint is a reference value: the endpoint policy
     * refuses to dial it, independently of readiness.
     *
     * Two guards on the same fact, deliberately. Readiness is what an operator
     * reads; the endpoint policy is what stops a socket opening. Either alone
     * would be a single point of failure for the thing §7 says must never
     * happen.
     */
    #[Test]
    public function the_endpoint_policy_and_the_readiness_engine_both_refuse_it(): void
    {
        $endpoint = 'https://203.0.113.11';

        $verdict = (new ProviderReadiness)->assess(
            (new ProviderCatalogue)->find('cloudflare'),
            $this->productionProvider($endpoint),
            testerAvailable: true,
        );

        self::assertFalse($verdict->isReady());

        $refused = false;

        try {
            $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: false, production: true);
        } catch (EndpointRefused) {
            $refused = true;
        }

        self::assertTrue($refused, 'The endpoint policy accepted what readiness refused.');
    }

    // ---------------------------------------------------------------------
    // The classifier's own edges
    // ---------------------------------------------------------------------

    #[Test]
    public function the_address_classifier_compares_prefixes_and_not_strings(): void
    {
        $values = new ReferenceValues;

        // Inside.
        self::assertTrue($values->isDocumentationAddress('192.0.2.0'));
        self::assertTrue($values->isDocumentationAddress('192.0.2.255'));
        self::assertTrue($values->isDocumentationAddress('3fff::1'));
        self::assertTrue($values->isDocumentationAddress('3fff:0fff:ffff::1'));

        // Outside, and each one is a string a substring check would confuse.
        self::assertFalse($values->isDocumentationAddress('192.0.20.1'));
        self::assertFalse($values->isDocumentationAddress('1192.0.2.1'));
        self::assertFalse($values->isDocumentationAddress('203.0.1130'));
        self::assertFalse($values->isDocumentationAddress('2001:db80::1'));
        // 3fff::/20 is two and a half bytes, so the third byte is compared
        // under a 0xF0 mask. A whole-byte comparison would wrongly accept
        // 3f00::1; a two-byte comparison would wrongly accept 3fff:1000::1.
        self::assertFalse($values->isDocumentationAddress('3f00::1'));
        self::assertFalse($values->isDocumentationAddress('3fff:1000::1'));
        self::assertFalse($values->isDocumentationAddress('3fff:ffff::1'));
        self::assertFalse($values->isDocumentationAddress('not an address'));
        self::assertFalse($values->isDocumentationAddress(''));
    }

    #[Test]
    public function the_identifier_classifier_recognises_the_scheme_and_nothing_wider(): void
    {
        $values = new ReferenceValues;

        self::assertTrue($values->isReferenceIdentifier('ref-node-alpha-1-a'));
        self::assertTrue($values->isReferenceIdentifier('lynomia-reference-topology'));

        self::assertFalse($values->isReferenceIdentifier('reference'));
        self::assertFalse($values->isReferenceIdentifier('refactor-service'));
        self::assertFalse($values->isReferenceIdentifier('ref'));
        self::assertFalse($values->isReferenceIdentifier('ref-'));
        self::assertFalse($values->isReferenceIdentifier('ref--double'));

        // Case-insensitive on purpose: the guard's question is "might this be a
        // reference value", and a shouted one still is.
        self::assertTrue($values->isReferenceIdentifier('REF-NODE-ALPHA-1-A'));

        // The validator asks the stricter question, because the topology file
        // has to be written the way the scheme says.
        self::assertTrue($values->isCanonicalReferenceIdentifier('ref-node-alpha-1-a'));
        self::assertFalse($values->isCanonicalReferenceIdentifier('REF-NODE-ALPHA-1-A'));
        self::assertFalse($values->isCanonicalReferenceIdentifier('Ref-Node'));
        self::assertFalse($values->isCanonicalReferenceIdentifier('ref_node'));
    }

    private function productionProvider(string $endpoint): ProviderInstance
    {
        return ProviderInstance::factory()->create([
            'category' => ProviderCategory::Dns,
            'driver' => 'cloudflare',
            'environment' => DeploymentEnvironment::Production,
            'endpoint' => $endpoint,
            'credential_reference_id' => CredentialReference::factory()->create([
                'state' => CredentialState::Valid,
                'environment' => DeploymentEnvironment::Production,
            ])->getKey(),
        ]);
    }
}
