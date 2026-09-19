<?php

declare(strict_types=1);

namespace Tests\Unit\Naming;

use Lynomia\Modules\Infrastructure\Domain\Naming\DnsSuffix;
use Lynomia\Modules\Infrastructure\Domain\Naming\InfrastructureNamingPolicy;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Naming\LogicalName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The naming standard, stated as the four questions it answers.
 *
 * ===========================================================================
 * WHY EVERY REFUSAL HAS A TWIN
 * ===========================================================================
 *
 * A validator is only worth having if the thing it refuses is a thing somebody
 * would otherwise do, and only safe to have if the thing it accepts is a thing
 * somebody needs. So each rejection below sits beside the legitimate value it
 * must not catch: `host:8443` is refused and `host` plus a port field is
 * accepted; a reserved example zone is refused for production and required for
 * the reference topology; an uppercase logical key is refused and an uppercase
 * rack code is not, because racks are stencilled `A1` in the real world.
 *
 * A standard without those twins drifts into a validator that refuses
 * everything unfamiliar, and the operators work around it by inventing a
 * second scheme — which is the problem this gap exists to end.
 */
final class TheStandardSaysWhatEachKindOfNameMayBeTest extends TestCase
{
    private InfrastructureNamingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new InfrastructureNamingPolicy;
    }

    // -----------------------------------------------------------------------
    // Logical identifiers
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('validLogicalKeys')]
    public function a_logical_identifier_is_lower_case_alphanumeric_segments_joined_by_dashes(string $value): void
    {
        $this->assertNull(
            $this->policy->problemWith(NamingConcept::ClusterSlug, $value),
            sprintf('"%s" is a spelling this platform already uses and the standard must accept it.', $value),
        );
    }

    public static function validLogicalKeys(): iterable
    {
        yield 'one segment' => ['alpha'];
        yield 'digits' => ['pve1'];
        yield 'several segments' => ['ref-cluster-alpha-1'];
        yield 'what the reference topology publishes' => ['debian-stable'];
        yield 'a segment that is only digits' => ['dc-1-a'];
    }

    #[Test]
    #[DataProvider('invalidLogicalKeys')]
    public function a_logical_identifier_refuses_what_would_become_two_spellings_of_one_thing(string $value, string $expected): void
    {
        $problem = $this->policy->problemWith(NamingConcept::ClusterSlug, $value);

        $this->assertNotNull($problem, sprintf('"%s" should not be a valid logical identifier.', $value));
        $this->assertStringContainsString($expected, $problem);
    }

    public static function invalidLogicalKeys(): iterable
    {
        yield 'upper case' => ['Cluster-01', 'not lower case'];
        yield 'leading dash' => ['-cluster', 'alphanumeric segments'];
        yield 'trailing dash' => ['cluster-', 'alphanumeric segments'];
        yield 'double dash' => ['cluster--01', 'alphanumeric segments'];
        yield 'underscore' => ['cluster_01', 'alphanumeric segments'];
        yield 'space' => ['cluster 01', 'alphanumeric segments'];
        yield 'dot' => ['cluster.01', 'alphanumeric segments'];
        yield 'empty' => ['', 'empty'];
        yield 'padded' => [' cluster ', 'whitespace'];
        // A display name may be Arabic. An identifier that goes into a URL, a
        // metric label and an IaC variable may not.
        yield 'arabic' => ['الرياض', 'not ASCII'];
    }

    #[Test]
    public function a_logical_identifier_is_refused_at_the_column_ceiling_and_accepted_one_character_below_it(): void
    {
        $limit = NamingConcept::ClusterSlug->maxLength();

        $this->assertNull($this->policy->problemWith(NamingConcept::ClusterSlug, str_repeat('a', $limit)));
        $this->assertStringContainsString(
            'longer than',
            (string) $this->policy->problemWith(NamingConcept::ClusterSlug, str_repeat('a', $limit + 1)),
        );
    }

    // -----------------------------------------------------------------------
    // Normalisation, which is a separate act from validation
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('normalisations')]
    public function normalising_for_creation_says_what_a_value_would_become(string $typed, string $expected): void
    {
        $this->assertSame($expected, $this->policy->normalizeForCreation(NamingConcept::ClusterSlug, $typed));
    }

    public static function normalisations(): iterable
    {
        yield 'upper case' => ['NODE-01', 'node-01'];
        yield 'spaces' => ['Node 01', 'node-01'];
        yield 'underscores' => ['node_01', 'node-01'];
        yield 'padding' => ['  node-01  ', 'node-01'];
        yield 'runs of punctuation' => ['node -- 01', 'node-01'];
        // Nothing usable: the empty string, refused by the validator, rather
        // than an identifier nobody typed.
        yield 'nothing ASCII' => ['الرياض', ''];
    }

    #[Test]
    public function three_spellings_of_one_identifier_share_one_collision_key(): void
    {
        $keys = array_map(
            fn (string $value): string => $this->policy->collisionKey(NamingConcept::ClusterSlug, $value),
            ['Node-01', 'node-01', 'NODE 01', 'node_01'],
        );

        $this->assertCount(1, array_unique($keys), 'These are one identity, so the platform must judge them as one.');
        $this->assertSame('node-01', $keys[0]);
    }

    #[Test]
    public function two_identifiers_that_are_genuinely_different_do_not_collide(): void
    {
        $this->assertNotSame(
            $this->policy->collisionKey(NamingConcept::ClusterSlug, 'node-01'),
            $this->policy->collisionKey(NamingConcept::ClusterSlug, 'node-02'),
        );
    }

    #[Test]
    public function a_provider_native_identifier_keeps_its_own_case_even_where_ours_would_not(): void
    {
        // Proxmox decides what its storages are called. `LOCAL-LVM` may be the
        // string its API answers to, and a normalised copy is a value it does
        // not recognise.
        $this->assertNull($this->policy->problemWith(NamingConcept::StorageProviderName, 'LOCAL-LVM'));
        $this->assertNull($this->policy->problemWith(NamingConcept::StorageProviderName, 'ceph_prod'));
        $this->assertSame('LOCAL-LVM', $this->policy->normalizeForCreation(NamingConcept::StorageProviderName, 'LOCAL-LVM'));

        // And two providers' identifiers that differ only in case are two
        // identifiers, because their scheme is theirs to decide.
        $this->assertNotSame(
            $this->policy->collisionKey(NamingConcept::StorageProviderName, 'local-lvm'),
            $this->policy->collisionKey(NamingConcept::StorageProviderName, 'LOCAL-LVM'),
        );
    }

    #[Test]
    public function a_provider_native_identifier_still_refuses_what_would_corrupt_a_request(): void
    {
        $this->assertStringContainsString('whitespace', (string) $this->policy->problemWith(NamingConcept::StorageProviderName, 'local lvm'));
        $this->assertStringContainsString('control character', (string) $this->policy->problemWith(NamingConcept::StorageProviderName, "local\nlvm"));
        $this->assertStringContainsString('empty', (string) $this->policy->problemWith(NamingConcept::StorageProviderName, ''));
    }

    #[Test]
    public function an_operator_code_keeps_the_case_somebody_stencilled_on_the_cabinet(): void
    {
        $this->assertNull($this->policy->problemWith(NamingConcept::RackCode, 'RA1'));
        $this->assertNull($this->policy->problemWith(NamingConcept::RackCode, 'E2E-R1'));
        $this->assertSame('RA1', $this->policy->normalizeForCreation(NamingConcept::RackCode, 'RA1'));

        // Unique case-insensitively all the same: one cabinet, one code.
        $this->assertSame(
            $this->policy->collisionKey(NamingConcept::RackCode, 'RA1'),
            $this->policy->collisionKey(NamingConcept::RackCode, 'ra1'),
        );
    }

    #[Test]
    public function a_display_name_may_be_arabic_and_is_never_normalised(): void
    {
        $this->assertNull($this->policy->problemWith(NamingConcept::DatacenterDisplayName, 'مركز البيانات المرجعي'));
        $this->assertNull($this->policy->problemWith(NamingConcept::DatacenterDisplayName, 'Reference Datacenter Alpha 1'));
        $this->assertSame(
            'Reference Datacenter Alpha 1',
            $this->policy->normalizeForCreation(NamingConcept::DatacenterDisplayName, 'Reference Datacenter Alpha 1'),
        );

        // What it may not carry is a newline, which is how a name becomes two
        // lines in a log, a CSV export or an alert annotation.
        $this->assertStringContainsString(
            'control character',
            (string) $this->policy->problemWith(NamingConcept::DatacenterDisplayName, "Reference\nDatacenter"),
        );
    }

    // -----------------------------------------------------------------------
    // Hostnames
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('validHostnames')]
    public function a_hostname_is_labels_and_nothing_else(string $value): void
    {
        $this->assertNull($this->policy->problemWith(NamingConcept::HostingNodeHostname, $value), $value);
    }

    public static function validHostnames(): iterable
    {
        yield 'qualified' => ['web-01.reference.example'];
        // A bare label is legitimate: it is what an operator types before a
        // zone exists, and refusing it forces somebody to invent one.
        yield 'bare label' => ['web-01'];
        yield 'mixed case, canonicalised on the way in' => ['Web-01.Lynomia.Example'];
        yield 'one trailing root dot' => ['web-01.reference.example.'];
        yield 'an A-label' => ['xn--mgbh0fb.example'];
    }

    #[Test]
    #[DataProvider('invalidHostnames')]
    public function a_hostname_field_refuses_everything_that_is_a_different_field(string $value, string $expected): void
    {
        $problem = $this->policy->problemWith(NamingConcept::HostingNodeHostname, $value);

        $this->assertNotNull($problem, sprintf('"%s" is not a hostname.', $value));
        $this->assertStringContainsString($expected, $problem);
    }

    public static function invalidHostnames(): iterable
    {
        // The one Gap 2 found in the reachability testers: a port inside a
        // name, which then travels into a certificate request.
        yield 'host and port' => ['web-01.reference.example:8443', 'carries a port'];
        yield 'a URL' => ['https://web-01.reference.example', 'is a URL'];
        yield 'user information' => ['root@web-01.reference.example', 'user information'];
        yield 'a path' => ['web-01.reference.example/api', 'carries a path'];
        yield 'an address' => ['192.0.2.10', 'IP address'];
        yield 'a bracketed address' => ['[2001:db8::1]', 'bracketed IP literal'];
        yield 'an empty label' => ['web-01..example', 'empty label'];
        yield 'a leading dash' => ['-web-01.example', 'not a valid hostname label'];
        yield 'an underscore' => ['web_01.example', 'not a valid hostname label'];
        yield 'whitespace' => ['web 01.example', 'whitespace'];
        yield 'a U-label' => ['مثال.example', 'not ASCII'];
        yield 'a label over 63' => [str_repeat('a', 64).'.example', 'longer than 63'];
        yield 'two trailing dots' => ['web-01.example..', 'empty label'];
    }

    #[Test]
    public function a_hostname_compares_the_way_dns_does(): void
    {
        $name = DnsName::tryFrom('Web-01.Reference.Example.');

        $this->assertNotNull($name);
        $this->assertSame('web-01.reference.example', $name->value());
        $this->assertTrue($name->equals('WEB-01.reference.example'));
        $this->assertTrue($name->isFullyQualified());
        $this->assertTrue($name->isUnder('reference.example'));

        // Label-wise, so a name that merely ends in the same letters is not
        // inside the zone.
        $other = DnsName::tryFrom('web-01.notreference.example');
        $this->assertNotNull($other);
        $this->assertFalse($other->isUnder('reference.example'));
    }

    // -----------------------------------------------------------------------
    // The production suffix, which is the operator's to choose
    // -----------------------------------------------------------------------

    #[Test]
    public function a_reserved_example_zone_is_a_valid_reference_suffix_and_never_a_production_one(): void
    {
        $this->assertNull(
            DnsSuffix::problemWith('dc1.reference.example', production: false),
            'The reference topology names its hosts under a reserved zone, and must be able to.',
        );

        $problem = DnsSuffix::problemWith('dc1.reference.example', production: true);

        $this->assertNotNull($problem, 'A production deployment must not inherit the reference zone.');
        $this->assertStringContainsString('reserved for documents', $problem);
    }

    #[Test]
    public function an_operator_configured_zone_is_accepted_for_production(): void
    {
        // The positive twin of the refusal above, and the whole point of the
        // suffix being configuration: a real zone works, and this repository
        // never has to know what it is.
        $this->assertNull(DnsSuffix::problemWith('dc1.operator-chosen-zone.net', production: true));
    }

    #[Test]
    #[DataProvider('impossibleSuffixes')]
    public function a_suffix_that_is_not_a_zone_is_refused_in_either_world(string $value, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) DnsSuffix::problemWith($value, production: false));
        $this->assertStringContainsString($expected, (string) DnsSuffix::problemWith($value, production: true));
    }

    public static function impossibleSuffixes(): iterable
    {
        yield 'a single label' => ['example', 'single label'];
        yield 'a URL' => ['https://reference.example', 'is a URL'];
        yield 'with a port' => ['reference.example:53', 'carries a port'];
        yield 'empty' => ['', 'empty'];
    }

    #[Test]
    public function composing_a_name_puts_one_dot_in_the_middle_and_refuses_a_label_that_is_already_a_name(): void
    {
        $suffix = DnsSuffix::tryFrom('dc1.reference.example', production: false);

        $this->assertNotNull($suffix);
        $this->assertSame('pve-01.dc1.reference.example', $suffix->compose('pve-01')?->value());
        $this->assertSame('pve-01.dc1.reference.example', $suffix->compose('PVE-01')?->value());

        // Already qualified, so composing would produce
        // `pve-01.old.example.dc1.reference.example` — a real name for nothing.
        $this->assertNull($suffix->compose('pve-01.old.example'));
        $this->assertNull($suffix->compose('pve 01'));
    }

    // -----------------------------------------------------------------------
    // The model itself
    // -----------------------------------------------------------------------

    #[Test]
    public function only_a_logical_identifier_is_treated_as_a_stable_identity(): void
    {
        $this->assertTrue($this->policy->isImmutable(NamingConcept::ClusterSlug));
        $this->assertTrue($this->policy->isImmutable(NamingConcept::TemplateSlug));

        // A display name renames freely; a hostname changes when DNS does; a
        // provider's identifier changes when the provider says so. None of
        // those is an identity this platform may have written into an order.
        $this->assertFalse($this->policy->isImmutable(NamingConcept::DatacenterDisplayName));
        $this->assertFalse($this->policy->isImmutable(NamingConcept::HostingNodeHostname));
        $this->assertFalse($this->policy->isImmutable(NamingConcept::NodeProviderName));
        $this->assertFalse($this->policy->isImmutable(NamingConcept::RackCode));
    }

    #[Test]
    public function the_shared_value_objects_are_the_only_syntax_in_play(): void
    {
        // Stated as a test because the alternative — a second regex in a form
        // request, a third in a seeder — is exactly how this platform ended up
        // with two naming schemes in the first place.
        $this->assertNull(LogicalName::problemWith('ref-node-alpha-1-a', 255));
        $this->assertSame('ra1', LogicalName::collisionKey('RA1'));
        $this->assertTrue(DnsName::isLabel('pve-01'));
        $this->assertFalse(DnsName::isLabel('pve-01.example'));
        $this->assertFalse(DnsName::isLabel('-pve'));
    }
}
