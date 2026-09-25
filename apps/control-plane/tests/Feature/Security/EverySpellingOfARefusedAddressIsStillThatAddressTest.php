<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Shared\Domain\Contracts\HostResolver;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use PhpToken;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionMethod;
use Tests\Support\StaticHostResolver;
use Tests\TestCase;
use UnitEnum;

/**
 * F-29: an address the policy refuses is refused however it is spelled.
 *
 * ---------------------------------------------------------------------------
 * The finding
 * ---------------------------------------------------------------------------
 *
 * `EndpointPolicy` compared strings. It refused `127.0.0.1` and
 * `169.254.169.254` as written, and then asked the system resolver about
 * anything that was not a dotted-quad or an IPv6 literal PHP recognised —
 * accepting whatever came back, and accepting outright when nothing came back.
 * Every one of the families below reached a socket by one of those two doors:
 *
 *   - non-canonical IPv4 numbers (`0x7f000001`, `2130706433`, `0177.0.0.1`,
 *     `127.1`), which `inet_aton` reads as addresses and which were accepted or
 *     refused depending on what this machine's C library did with them;
 *   - IPv4 addresses carried inside IPv6 ones — compatible, mapped, SIIT;
 *   - the transition prefixes that do the same by design: NAT64, 6to4, Teredo;
 *   - IANA special-purpose ranges PHP's filter flags do not know about;
 *   - zone identifiers, which name an interface on this host;
 *   - the root label and the UTS-46 separators (`。．｡`), which spell the
 *     same name so that every comparison by name misses it;
 *   - IPv6 literals written in a form other than the one compared against.
 *
 * Remediation found more roads to the same place, and they are here too: a
 * URL parser that rewrites control characters into `_` and hands the policy a
 * host that was never written; a name whose only record is AAAA, which the
 * resolver did not ask for; and a name that resolves to nothing at all, which
 * ran the address loop zero times and was accepted.
 *
 * ---------------------------------------------------------------------------
 * Both entry points, every family
 * ---------------------------------------------------------------------------
 *
 * The policy is asked through `assertMachineAddress` (a host, optionally with a
 * port) and through `assertProviderEndpoint` (a URL). Every spelling is fed to
 * both, and each refusal must carry the reason that names its family — a
 * refusal for the wrong reason is a refusal that will stop happening when the
 * unrelated rule that caught it is relaxed.
 *
 * ---------------------------------------------------------------------------
 * The anchors, and which row pins which
 * ---------------------------------------------------------------------------
 *
 * Every pattern in the policy that must match a whole string ends in `\z`,
 * because PCRE's `$` also matches before a final newline. Whether a test can
 * see the difference is a different question for each anchor, and the answer
 * is not written in this file: the partition row derives it by rebuilding the
 * policy with each anchor turned back into `$` and running the rows that claim
 * to pin anchors against every copy. See that row's docblock for what the
 * derivation reads and what it cannot see.
 */
final class EverySpellingOfARefusedAddressIsStillThatAddressTest extends TestCase
{
    /**
     * Addresses whose every spelling must be refused: this host, the metadata
     * services and the unspecified address.
     *
     * @var list<string>
     */
    private const array SENSITIVE = ['127.0.0.1', '169.254.169.254', '0.0.0.0', '100.100.100.200'];

    /**
     * The rows that claim to pin an anchor, and the helper each one runs.
     *
     * The helpers take the policy class they judge, so the partition row can
     * run them against a rebuilt copy; the rows run them against the shipped
     * policy. Keyed row => helper.
     *
     * @var array<string, string>
     */
    private const array KEEPERS = [
        'the_anchors_end_the_string' => 'anchorsEndTheString',
        'a_host_label_is_judged_as_the_dns_module_judges_it_but_for_the_underscore_and_the_newline' => 'hostLabelsAgreeWithTheDnsModule',
        'a_numeric_last_label_is_judged_as_the_dns_module_judges_it' => 'numericLabelsAgreeWithTheDnsModule',
    ];

    /**
     * Which helper fails against a copy of the policy with each anchor turned
     * into `$`, keyed by the anchor's name.
     *
     * An anchor's name is the constant it initialises or, for a literal at a
     * call site, the method it is written in. An empty list is an anchor no
     * row pins; see `HOST_CHARACTERS` in the policy for why that one is left.
     *
     * @var array<string, list<string>>
     */
    private const array PARTITION = [
        'HOST_CHARACTERS' => [],
        'HOST_LABEL' => ['hostLabelsAgreeWithTheDnsModule'],
        'NUMERIC_LABEL' => ['numericLabelsAgreeWithTheDnsModule'],
        'assertMachineAddress' => ['anchorsEndTheString'],
        'assertPort' => ['anchorsEndTheString'],
        'assertProviderEndpoint' => ['anchorsEndTheString'],
        'hostWithoutPort' => ['anchorsEndTheString'],
    ];

    /** @var list<string>|null */
    private static ?array $labelSpace = null;

    /** @var array<string, bool> */
    private static array $dnsModuleAccepts = [];

    // ------------------------------------------------------------------
    // The seven families, through both doors
    // ------------------------------------------------------------------

    /**
     * Hosts refused on both roads, and the words the refusal must contain.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function spellingsOfARefusedAddress(): iterable
    {
        // Non-canonical IPv4 numbers. The last label is a number, so a
        // resolver reads the whole name as an address.
        yield 'hex, one part' => ['0x7f000001', 'four decimal parts'];
        yield 'hex, one part, upper case' => ['0X7F000001', 'four decimal parts'];
        yield 'decimal, one part' => ['2130706433', 'four decimal parts'];
        yield 'octal, four parts' => ['0177.0.0.1', 'four decimal parts'];
        yield 'decimal, two parts' => ['127.1', 'four decimal parts'];
        yield 'hex and decimal, two parts' => ['0x7f.1', 'four decimal parts'];
        yield 'metadata, hex' => ['0xa9fea9fe', 'four decimal parts'];
        yield 'metadata, octal' => ['0251.0376.0251.0376', 'four decimal parts'];
        yield 'metadata, three parts' => ['169.254.43518', 'four decimal parts'];
        yield 'a leading zero in one part' => ['169.254.169.0254', 'four decimal parts'];
        yield 'a name whose last label is a number' => ['bmc.127', 'four decimal parts'];
        yield 'a last label that is a bare hex prefix' => ['bmc.0x', 'four decimal parts'];

        // IPv4 inside IPv6.
        yield 'IPv4-mapped, dotted' => ['::ffff:169.254.169.254', 'IPv4-in-IPv6'];
        yield 'IPv4-mapped, hex' => ['::ffff:a9fe:a9fe', 'IPv4-in-IPv6'];
        yield 'IPv4-compatible, dotted' => ['::169.254.169.254', 'IPv4-in-IPv6'];
        yield 'IPv4-compatible, hex' => ['::a9fe:a9fe', 'IPv4-in-IPv6'];
        yield 'SIIT' => ['::ffff:0:a9fe:a9fe', 'IPv4-in-IPv6'];
        yield 'IPv4-mapped loopback, written long' => ['0:0:0:0:0:ffff:7f00:1', 'IPv4-in-IPv6'];

        // The transition prefixes, which embed an IPv4 address by design.
        yield 'NAT64 well-known prefix' => ['64:ff9b::a9fe:a9fe', 'NAT64'];
        yield 'NAT64 local-use prefix' => ['64:ff9b:1::a9fe:a9fe', 'NAT64'];
        yield '6to4' => ['2002:a9fe:a9fe::1', '6to4'];
        yield 'Teredo' => ['2001:0:4136:e378:8000:63bf:56ff:fefe', 'Teredo'];
        yield 'the retired 6to4 relay anycast block' => ['192.88.99.1', '6to4 relay'];

        // IANA special-purpose ranges that PHP's reserved set does not hold.
        yield 'IETF protocol assignments, v4' => ['192.0.0.8', 'reserved'];
        yield 'NAT64 discovery, v4' => ['192.0.0.170', 'reserved'];
        yield 'benchmarking, first half' => ['198.18.0.1', 'reserved'];
        yield 'benchmarking, second half' => ['198.19.255.254', 'reserved'];
        yield 'reserved for future use' => ['240.0.0.1', 'reserved'];
        yield 'limited broadcast' => ['255.255.255.255', 'reserved'];
        yield 'this network' => ['0.1.2.3', 'reserved'];
        yield 'multicast, v4' => ['224.0.0.251', 'reserved'];
        yield 'discard-only' => ['100::1', 'reserved'];
        yield 'site-local' => ['fec0::1', 'reserved'];
        yield 'benchmarking, v6' => ['2001:2::1', 'reserved'];
        yield 'multicast, v6' => ['ff02::fb', 'reserved'];

        // Zone identifiers name an interface on this host.
        yield 'a zone on a link-local address' => ['fe80::1%eth0', 'zone identifier'];
        yield 'a zone on a public address' => ['2001:4860:4860::8888%eth0', 'zone identifier'];

        // The root label and the separators UTS-46 maps to a dot.
        yield 'metadata with the root label' => ['169.254.169.254.', 'trailing dot'];
        yield 'localhost with the root label' => ['localhost.', 'trailing dot'];
        yield 'a metadata name with the root label' => ['metadata.google.internal.', 'trailing dot'];
        yield 'ideographic full stops' => ['169。254。169。254', 'not ASCII'];
        yield 'fullwidth full stops' => ['169．254．169．254', 'not ASCII'];
        yield 'halfwidth ideographic full stops' => ['169｡254｡169｡254', 'not ASCII'];
        yield 'a metadata name with ideographic full stops' => ['metadata。google。internal', 'not ASCII'];

        // IPv6 written other than as compared.
        yield 'metadata, a zero group written out' => ['fd00:ec2:0::254', 'metadata'];
        yield 'metadata, every group written out' => ['fd00:0ec2:0000:0000:0000:0000:0000:0254', 'metadata'];
        yield 'metadata, upper case' => ['FD00:EC2::254', 'metadata'];
        yield 'loopback, every group written out' => ['0:0:0:0:0:0:0:1', 'IPv4-in-IPv6'];
        yield 'loopback, a zero group kept' => ['::0:1', 'IPv4-in-IPv6'];
        yield 'loopback, leading zeros' => ['0000::0001', 'IPv4-in-IPv6'];
    }

    #[Test]
    #[DataProvider('spellingsOfARefusedAddress')]
    public function every_family_is_refused_as_a_machine_address(string $host, string $reason): void
    {
        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress($host, production: false),
            $reason,
            sprintf('The machine address %s', json_encode($host)),
        );
    }

    #[Test]
    #[DataProvider('spellingsOfARefusedAddress')]
    public function every_family_is_refused_as_a_provider_endpoint_even_on_our_own_hardware(string $host, string $reason): void
    {
        // On our own hardware is the permissive side: private addresses are
        // allowed there. None of these is a private address.
        $endpoint = self::urlFor($host);

        self::assertRefused(
            fn () => $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: false),
            $reason,
            sprintf('The provider endpoint %s', json_encode($endpoint)),
        );
    }

    /**
     * Bytes a URL parser changes before the policy sees them.
     *
     * `parse_url` rewrites every control character in a host into `_`, so the
     * policy used to be handed `169.254.169.254_` — on no list, not a literal,
     * not a number — which resolved to nothing and was accepted, while the
     * bytes that would reach the socket were the original ones. Space and
     * backslash are carried into the host verbatim instead, which is why a
     * "the host appears in what was written" rule alone is not enough and a
     * rule about what a host may be written with sits beside it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function endpointsAParserRewrites(): iterable
    {
        yield 'a newline after the address' => ["https://169.254.169.254\n/", 'URL parser'];
        yield 'a tab after the address' => ["https://169.254.169.254\t/", 'URL parser'];
        yield 'a carriage return after the address' => ["https://169.254.169.254\r/", 'URL parser'];
        yield 'a NUL after the address' => ["https://169.254.169.254\x00/", 'URL parser'];
        yield 'a DEL after the address' => ["https://169.254.169.254\x7f/", 'URL parser'];
        yield 'a newline inside a name' => ["https://meta\ndata/", 'URL parser'];
        yield 'a backslash after the address' => ['https://169.254.169.254\\/', 'written with letters'];
        yield 'a space after the address' => ['https://169.254.169.254 /', 'written with letters'];
    }

    #[Test]
    #[DataProvider('endpointsAParserRewrites')]
    public function an_endpoint_is_judged_by_the_host_that_was_written_and_not_the_one_a_parser_made_of_it(string $endpoint, string $reason): void
    {
        foreach ([true, false] as $onOurHardware) {
            self::assertRefused(
                fn () => $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: $onOurHardware, production: true),
                $reason,
                sprintf('The provider endpoint %s', json_encode($endpoint)),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function machineAddressesWithSomethingInTheHost(): iterable
    {
        yield 'a newline after the address' => ["169.254.169.254\n", 'control character'];
        yield 'a newline before the port' => ["10.66.0.2\n:8006", 'control character'];
        yield 'a tab inside a name' => ["bmc-01\t.mgmt.lynomia-fleet.net", 'control character'];
        yield 'a backslash' => ['bmc-01\\mgmt', 'written with letters'];
        yield 'a zone inside brackets' => ['[fe80::1%25eth0]', 'zone identifier'];
        yield 'a zone inside brackets, with a port' => ['[fe80::1%25eth0]:443', 'zone identifier'];
        yield 'a colon that is not IPv6' => ['bmc:01:x', 'not an IPv6 address'];
        yield 'an empty label' => ['bmc-01..lynomia-fleet.net', 'label'];
        yield 'a label ending in a hyphen' => ['bmc-.lynomia-fleet.net', 'label'];
    }

    #[Test]
    #[DataProvider('machineAddressesWithSomethingInTheHost')]
    public function a_machine_address_is_judged_by_every_byte_of_its_host(string $address, string $reason): void
    {
        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress($address, production: false),
            $reason,
            sprintf('The machine address %s', json_encode($address)),
        );
    }

    // ------------------------------------------------------------------
    // The fuzz
    // ------------------------------------------------------------------

    #[Test]
    public function no_inet_aton_spelling_of_a_sensitive_address_is_accepted_or_even_resolved(): void
    {
        /*
         * Every way `inet_aton` will read one of the sensitive addresses:
         * four, three, two and one parts, each part in decimal, octal or hex,
         * in every combination — then each crossed with the decorations an
         * operator's paste or an attacker's patience adds. Every one is refused
         * on both roads, and the resolver is never asked: an address spelled
         * as a number is judged as a number, not handed to a C library to
         * guess at.
         */
        $forms = [];

        foreach (self::SENSITIVE as $address) {
            foreach (self::inetAtonSpellings($address) as $spelling) {
                foreach (self::decorated($spelling) as $form) {
                    $forms[$form] = true;
                }
            }
        }

        // The generator is what this row rests on, so its reach is checked
        // against spellings written by hand rather than trusted.
        foreach (['0x7f000001', '2130706433', '017700000001', '127.1', '0177.1', '0x7f.0.1', '127.0.0.1.', '0XA9FEA9FE', '[169.254.43518]', '0x7f000001:443', '127。0。0。1'] as $expected) {
            self::assertArrayHasKey($expected, $forms, sprintf('The spelling generator does not produce %s.', $expected));
        }

        foreach (array_keys($forms) as $form) {
            $form = (string) $form;

            self::assertRefused(
                fn () => $this->policy()->assertMachineAddress($form, production: false),
                'is refused',
                sprintf('The machine address %s', json_encode($form)),
            );

            $endpoint = sprintf('https://%s/', $form);

            self::assertRefused(
                fn () => $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: true, production: false),
                'is refused',
                sprintf('The provider endpoint %s', json_encode($endpoint)),
            );
        }

        self::assertSame([], $this->resolver()->asked(), 'A spelling of an address reached the resolver. It should have been judged as the address it spells.');
    }

    #[Test]
    public function no_ipv6_embedding_of_a_sensitive_address_is_accepted_or_even_resolved(): void
    {
        foreach (self::SENSITIVE as $address) {
            foreach (self::ipv6Embeddings($address) as $what => $embedding) {
                self::assertRefused(
                    fn () => $this->policy()->assertMachineAddress($embedding, production: false),
                    'reserved',
                    sprintf('%s as %s (%s)', $address, $what, $embedding),
                );

                self::assertRefused(
                    fn () => $this->policy()->assertProviderEndpoint(sprintf('https://[%s]:8443/', $embedding), controlledDriver: false, onOurHardware: true, production: false),
                    'reserved',
                    sprintf('%s as %s (%s) inside a URL', $address, $what, $embedding),
                );
            }
        }

        self::assertSame([], $this->resolver()->asked());
    }

    // ------------------------------------------------------------------
    // What a name resolves to
    // ------------------------------------------------------------------

    #[Test]
    public function a_name_the_resolver_has_no_address_for_is_refused_rather_than_waved_through(): void
    {
        /*
         * The address loop used to run zero times for a name that resolved to
         * nothing, and the name was accepted with nothing checked. That made
         * every refusal the policy makes about a name conditional on the
         * resolver having answered. The cost is real and is written down in
         * docs/security.md: a name must resolve before it can be registered.
         */
        $this->resolver()->answer('bmc-07.mgmt.lynomia-fleet.net', []);
        $this->resolver()->answer('panel-07.lynomia-hosting.net', []);

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress('bmc-07.mgmt.lynomia-fleet.net:8443', production: false),
            'resolves to no address',
            'A machine name with no address',
        );

        self::assertRefused(
            fn () => $this->policy()->assertProviderEndpoint('https://panel-07.lynomia-hosting.net:2087/', controlledDriver: false, onOurHardware: true, production: false),
            'resolves to no address',
            'A provider name with no address',
        );
    }

    #[Test]
    public function a_name_with_only_an_ipv6_answer_is_judged_by_that_answer(): void
    {
        $this->resolver()->answer('aaaa-only.lynomia-fleet.net', ['fd00:ec2::254']);
        $this->resolver()->answer('mapped.lynomia-fleet.net', ['::ffff:169.254.169.254']);
        $this->resolver()->answer('public-v6.lynomia-fleet.net', ['2001:4860:4860::8888']);

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress('aaaa-only.lynomia-fleet.net', production: false),
            'metadata',
            'A name whose only answer is the IPv6 metadata address',
        );

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress('mapped.lynomia-fleet.net', production: false),
            'IPv4-in-IPv6',
            'A name whose only answer maps the metadata address into IPv6',
        );

        $this->policy()->assertMachineAddress('public-v6.lynomia-fleet.net', production: true);
        $this->policy()->assertProviderEndpoint('https://public-v6.lynomia-fleet.net/', controlledDriver: false, onOurHardware: false, production: true);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function every_answer_is_judged_and_one_bad_answer_is_enough(): void
    {
        $this->resolver()->answer('round-robin.lynomia-fleet.net', ['8.8.8.8', '127.0.0.1']);
        $this->resolver()->answer('respelled.lynomia-fleet.net', ['0:0:0:0:0:0:0:1']);
        $this->resolver()->answer('upper.lynomia-fleet.net', ['FD00:EC2::254']);
        $this->resolver()->answer('garbage.lynomia-fleet.net', ['not-an-address']);

        foreach ([
            'round-robin.lynomia-fleet.net' => 'reserved',
            'respelled.lynomia-fleet.net' => 'IPv4-in-IPv6',
            'upper.lynomia-fleet.net' => 'metadata',
            'garbage.lynomia-fleet.net' => 'not an address',
        ] as $name => $reason) {
            self::assertRefused(
                fn () => $this->policy()->assertMachineAddress($name, production: false),
                $reason,
                sprintf('The name %s', $name),
            );
        }
    }

    #[Test]
    public function a_name_is_resolved_as_it_was_canonicalised_and_without_its_port(): void
    {
        $this->resolver()->answer('bmc-09.mgmt.lynomia-fleet.net', ['127.0.0.1']);

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress('BMC-09.MGMT.LYNOMIA-FLEET.NET:8443', production: false),
            'reserved',
            'An upper-case name with a port',
        );

        self::assertSame(['bmc-09.mgmt.lynomia-fleet.net'], $this->resolver()->asked());
    }

    // ------------------------------------------------------------------
    // What must still be accepted
    // ------------------------------------------------------------------

    #[Test]
    public function the_ordinary_estate_is_still_accepted_in_production(): void
    {
        /*
         * The positive twin. A policy that refused everything would pass every
         * row above; these are the shapes a real estate is made of.
         */
        foreach ([
            '10.66.0.2',
            '10.66.0.2:8006',
            '172.16.4.9:623',
            '2001:4860:4860::8888',
            '[2001:4860:4860::8888]',
            '[2001:4860:4860::8888]:8443',
            'fd12:3456:789a::10',
            'bmc-01.mgmt.lynomia-fleet.net',
            'bmc-01.mgmt.lynomia-fleet.net:8443',
            'BMC-01.MGMT.LYNOMIA-FLEET.NET',
            'bmc_01.mgmt.lynomia-fleet.net',
            'node1.dc2.lynomia-fleet.net',
            'x.123abc',
            '8.8.8.8',
        ] as $address) {
            $this->policy()->assertMachineAddress($address, production: true);
        }

        foreach ([
            ['https://api.cloudflare.com/client/v4', false],
            ['https://10.66.0.5:8006/', true],
            ['https://[2001:4860:4860::8888]:8443/', false],
            ['https://panel_01.lynomia-hosting.net:2087/', true],
            ['https://API.Some-Dns-Provider.net/', false],
        ] as [$endpoint, $onOurHardware]) {
            $this->policy()->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: $onOurHardware, production: true);
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_host_name_is_at_most_two_hundred_and_fifty_three_characters(): void
    {
        $label = static fn (string $letter, int $length): string => str_repeat($letter, $length);

        $longest = implode('.', [$label('a', 63), $label('b', 63), $label('c', 63), $label('d', 61)]);
        $tooLong = implode('.', [$label('a', 63), $label('b', 63), $label('c', 63), $label('d', 62)]);

        self::assertSame(253, strlen($longest));
        self::assertSame(254, strlen($tooLong));

        $this->policy()->assertMachineAddress($longest, production: false);

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress($tooLong, production: false),
            'longer than 253',
            'A 254-character name',
        );

        self::assertRefused(
            fn () => $this->policy()->assertMachineAddress(sprintf('%s.lynomia-fleet.net', $label('a', 64)), production: false),
            'label',
            'A name with a 64-character label',
        );
    }

    // ------------------------------------------------------------------
    // The rows that pin anchors
    // ------------------------------------------------------------------

    #[Test]
    public function the_anchors_end_the_string(): void
    {
        $this->anchorsEndTheString(EndpointPolicy::class);
    }

    #[Test]
    public function a_host_label_is_judged_as_the_dns_module_judges_it_but_for_the_underscore_and_the_newline(): void
    {
        $this->hostLabelsAgreeWithTheDnsModule(EndpointPolicy::class);
    }

    #[Test]
    public function a_numeric_last_label_is_judged_as_the_dns_module_judges_it(): void
    {
        $this->numericLabelsAgreeWithTheDnsModule(EndpointPolicy::class);
    }

    /**
     * The partition: which row fails when each anchor is turned into `$`.
     *
     * ---------------------------------------------------------------------
     * Why this is derived rather than written
     * ---------------------------------------------------------------------
     *
     * How many of the policy's anchors a test can see used to be stated in
     * prose, in several places, and every correction of the number left one
     * of the places behind. So nothing states it. This row reads the policy's
     * source, finds each anchor, rebuilds the class with that one anchor
     * turned into `$`, runs the pinning rows' helpers against the copy, and
     * compares what failed with `PARTITION` by name — a diff names the anchor
     * whose pin moved. Deleting one assertion from a helper, so that the
     * helper still passes against the shipped policy but no longer sees the
     * difference, turns this row red and names the anchor that lost its pin.
     *
     * ---------------------------------------------------------------------
     * What the walk sees, and the guards that keep it honest
     * ---------------------------------------------------------------------
     *
     * The walk sees a `\z` written as two contiguous characters inside one
     * single-quoted `T_CONSTANT_ENCAPSED_STRING`, and no other spelling. It is
     * a reading of the text standing in for a property of the execution, so
     * every way of holding a pattern the walk would not see is refused by a
     * guard over a property with one source, rather than by a list:
     *
     *   - No concatenation anywhere in the file. PHP has two concatenation
     *     operators, the binary `.` and `.=`, and a `\z` split across two
     *     literals, or assembled from `chr(92)`, needs one of them.
     *   - Every call that compiles a pattern passes it as one literal or as a
     *     class constant. A callee written down as a name is one of four
     *     token kinds — `T_STRING`, `T_NAME_QUALIFIED`,
     *     `T_NAME_FULLY_QUALIFIED`, `T_NAME_RELATIVE` — and function names are
     *     case-insensitive, so all four are matched and the last segment is
     *     compared without case. The compilers are PCRE's own functions whose
     *     first parameter is named `pattern`, asked of the running PHP rather
     *     than listed. That catches a heredoc, a nowdoc, `sprintf`, a local
     *     variable, and a first-class callable `preg_match(...)`, whose first
     *     argument is the ellipsis.
     *   - No function import in any of its forms (`use function x;`,
     *     `use function a\{x, y};`, `use a\{function x};`). An import is the
     *     one construct that can make a written name's last segment differ
     *     from the function called.
     *   - Every string the class holds in a constant that contains `\z` is one
     *     the walk found. A constant's value is null, a bool, an int, a float,
     *     a string, an array of those, or an enum case; enum cases are refused
     *     outright, because the string behind one is declared where the walk
     *     does not look.
     *   - No parent class, no interface and no trait, because each can hold
     *     patterns or constants in a file the walk does not open.
     *
     * Measured and left outside, and named so that nobody reads the guards as
     * closing them: a callee held in a variable (`$match = 'preg_match';
     * $match(…)`), and a pattern compiled by something that is not PCRE's.
     *
     * The rows' helpers are also guarded. Each must pass against the shipped
     * policy and raise the assertion count, so a helper emptied out cannot make
     * every anchor look unpinned; and each public row must make as many
     * assertions as its helper does against the shipped policy, so a row
     * reduced to `assertTrue(true)` is caught. That second comparison is an
     * equality and nothing more: a row padded to the same count with
     * assertions of nothing passes it. What still runs the shipped policy
     * through the helper in that case is this guard, which calls every helper
     * against `EndpointPolicy::class` itself.
     */
    #[Test]
    public function which_anchor_each_row_pins_is_derived_from_the_policy_and_never_written_down(): void
    {
        $this->theHelpersRunTheShippedPolicy();

        $file = (string) (new ReflectionClass(EndpointPolicy::class))->getFileName();
        $source = (string) file_get_contents($file);
        $tokens = PhpToken::tokenize($source);

        $this->theWalkSeesEveryAnchorTheClassHolds($tokens);

        $texts = array_map(static fn (PhpToken $token): string => $token->text, $tokens);

        self::assertSame($source, implode('', $texts), 'The token stream does not rebuild the file byte for byte, so a mutated copy would not be the policy with one change.');

        $anchors = self::anchorsIn($tokens);

        self::assertSame(
            array_keys(self::PARTITION),
            array_keys($anchors),
            'The policy holds a different set of anchors from the one this row expects. Name the new one in PARTITION with the rows that pin it — or with none, and say why in the policy.',
        );

        $partition = [];

        foreach ($anchors as $name => $index) {
            $partition[$name] = $this->helpersThatNotice(self::rebuiltWithout($tokens, $texts, $index, $name));
        }

        self::assertSame(self::PARTITION, $partition, 'An anchor gained or lost the row that pins it.');
    }

    // ------------------------------------------------------------------
    // The helpers the pinning rows run
    // ------------------------------------------------------------------

    /**
     * Each anchor written at a call site, fed the one input its `$` twin would
     * let through, beside the control that shows the input is otherwise good.
     *
     * @param  class-string  $policyClass
     */
    private function anchorsEndTheString(string $policyClass): void
    {
        $policy = $this->policyBuiltFrom($policyClass);

        // assertProviderEndpoint's controlled-driver marker.
        $policy->assertProviderEndpoint('fake://connected', controlledDriver: true, onOurHardware: false, production: false);
        self::assertRefused(
            static fn () => $policy->assertProviderEndpoint("fake://connected\n", controlledDriver: true, onOurHardware: false, production: false),
            'controlled driver',
            'assertProviderEndpoint: a controlled-driver marker with a newline after it',
        );

        // assertMachineAddress's controlled-driver marker.
        $policy->assertMachineAddress('fake://connected', production: false);
        self::assertRefused(
            static fn () => $policy->assertMachineAddress("fake://connected\n", production: false),
            'rehearsal',
            'assertMachineAddress: a fake address with a newline after it',
        );

        // hostWithoutPort's bracketed literal.
        $policy->assertMachineAddress('[2001:4860:4860::8888]', production: false);
        self::assertRefused(
            static fn () => $policy->assertMachineAddress("[2001:4860:4860::8888]\n", production: false),
            'control character',
            'hostWithoutPort: a bracketed literal with a newline after it',
        );

        // assertPort.
        $policy->assertMachineAddress('10.66.0.2:8006', production: false);
        self::assertRefused(
            static fn () => $policy->assertMachineAddress("10.66.0.2:8006\n", production: false),
            'not a port number',
            'assertPort: a port with a newline after it',
        );
    }

    /**
     * `HOST_LABEL` against the Dns module's label rule, over every label of one
     * and two bytes and every `a<byte>a`.
     *
     * The two rules are expected to differ in exactly two ways, and the row
     * says which way each goes rather than counting them:
     *
     *   - an underscore: a host label may carry one, and a DNS record name the
     *     platform writes may not. Deliberate.
     *   - a final newline: `DomainName::assertLabel` is anchored with `$`,
     *     which admits `ab\n`, and this rule is anchored with `\z`, which does
     *     not. That is a defect in the Dns module — an interior label carrying
     *     a newline survives into a `DomainName`'s value — recorded in the
     *     round-two ledger as an unnumbered observation and not repaired here.
     *
     * What pins this class's anchor is the second assertion below, which does
     * not depend on the Dns module keeping its defect: every swept label that
     * ends in a newline is refused.
     *
     * @param  class-string  $policyClass
     */
    private function hostLabelsAgreeWithTheDnsModule(string $policyClass): void
    {
        $pattern = self::privateConstant($policyClass, 'HOST_LABEL');

        $underscore = [];
        $newline = [];
        $unexplained = [];
        $newlineAccepted = [];

        foreach (self::labelSpace() as $label) {
            $ours = preg_match($pattern, $label) === 1;
            $theirs = self::dnsModuleAccepts($label);

            if ($ours && str_ends_with($label, "\n")) {
                $newlineAccepted[] = json_encode($label);
            }

            if ($ours === $theirs) {
                continue;
            }

            if ($ours && str_contains($label, '_')) {
                $underscore[] = $label;
            } elseif (! $ours && str_ends_with($label, "\n") && self::dnsModuleAccepts(rtrim($label, "\n"))) {
                $newline[] = $label;
            } else {
                $unexplained[] = json_encode($label);
            }
        }

        self::assertSame([], $unexplained, 'The host label rule and the Dns module disagree about these labels, and neither difference this row declares explains them.');
        self::assertSame([], $newlineAccepted, 'HOST_LABEL accepted a label that ends in a newline, which is what a `$` anchor does.');
        self::assertNotSame([], $underscore, 'No underscore label separates the two rules any more; the policy has stopped accepting underscores, and its docblock says it accepts them.');

        foreach ($newline as $label) {
            self::assertTrue(self::dnsModuleAccepts(rtrim($label, "\n")), 'A newline disagreement whose label the Dns module would refuse without the newline.');
        }
    }

    /**
     * `NUMERIC_LABEL` against `DomainName::fromString`'s `ctype_digit` over the
     * same space. As shipped they agree on every label; a `$` anchor makes the
     * policy call `7\n` a number where `ctype_digit` does not.
     *
     * @param  class-string  $policyClass
     */
    private function numericLabelsAgreeWithTheDnsModule(string $policyClass): void
    {
        $pattern = self::privateConstant($policyClass, 'NUMERIC_LABEL');

        $disagreements = [];

        foreach (self::labelSpace() as $label) {
            if ((preg_match($pattern, $label) === 1) !== ctype_digit($label)) {
                $disagreements[] = json_encode($label);
            }
        }

        self::assertSame([], $disagreements, 'NUMERIC_LABEL and ctype_digit disagree about whether these labels are numbers.');
    }

    // ------------------------------------------------------------------
    // The partition's machinery
    // ------------------------------------------------------------------

    private function theHelpersRunTheShippedPolicy(): void
    {
        foreach (self::KEEPERS as $row => $helper) {
            $before = Assert::getCount();
            $this->{$helper}(EndpointPolicy::class);
            $byHelper = Assert::getCount() - $before;

            self::assertGreaterThan(0, $byHelper, sprintf('%s passed the shipped policy without asserting anything, which would make every anchor it is supposed to pin look unpinned.', $helper));

            $before = Assert::getCount();
            $this->{$row}();
            $byRow = Assert::getCount() - $before;

            self::assertSame($byHelper, $byRow, sprintf('The row %s no longer makes the assertions %s makes against the shipped policy.', $row, $helper));
        }
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function theWalkSeesEveryAnchorTheClassHolds(array $tokens): void
    {
        $class = new ReflectionClass(EndpointPolicy::class);

        self::assertFalse($class->getParentClass(), 'The policy has a parent class, whose patterns and constants live in a file the walk does not open.');
        self::assertSame([], $class->getInterfaceNames(), 'The policy implements an interface, whose constants live in a file the walk does not open.');
        self::assertSame([], $class->getTraitNames(), 'The policy uses a trait, whose patterns live in a file the walk does not open.');

        $concatenations = [];
        $imports = [];
        $doubleQuotedAnchors = [];
        $literals = [];

        foreach ($tokens as $index => $token) {
            if ($token->text === '.' || $token->id === T_CONCAT_EQUAL) {
                $concatenations[] = $token->line;
            }

            if ($token->id === T_USE && self::isAnImport($tokens, $index) && self::importsAFunction($tokens, $index)) {
                $imports[] = $token->line;
            }

            if ($token->id === T_CONSTANT_ENCAPSED_STRING && str_contains($token->text, '\z')) {
                if (! str_starts_with($token->text, "'")) {
                    $doubleQuotedAnchors[] = $token->line;
                }

                $literals[] = self::singleQuoted($token->text);
            }
        }

        self::assertSame([], $concatenations, 'The policy concatenates on these lines. A pattern can be assembled that way where the walk cannot see it; build messages with sprintf.');
        self::assertSame([], $imports, 'The policy imports a function on these lines, which can make a written name call something other than what it says.');
        self::assertSame([], $doubleQuotedAnchors, 'An anchor is written in a double-quoted string on these lines; write patterns single-quoted.');

        $compilers = array_values(array_filter(
            get_extension_funcs('pcre') ?: [],
            static fn (string $function): bool => ((new ReflectionFunction($function))->getParameters()[0] ?? null)?->getName() === 'pattern',
        ));

        self::assertContains('preg_match', $compilers, 'PCRE did not report preg_match as a function taking a pattern, so the list below means nothing.');

        $unreadable = [];

        foreach ($tokens as $index => $token) {
            if (! self::isACallTo($tokens, $index, $compilers)) {
                continue;
            }

            if (! self::firstArgumentIsOneLiteralOrAConstant($tokens, $index)) {
                $unreadable[] = sprintf('line %d: %s', $token->line, $token->text);
            }
        }

        self::assertSame([], $unreadable, 'These calls compile a pattern that is not one literal or one class constant, so the walk cannot see whether it is anchored.');

        $unseen = [];

        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== EndpointPolicy::class) {
                continue;
            }

            foreach (self::stringsIn($constant->getValue(), $constant->getName()) as $where => $value) {
                if (str_contains($value, '\z') && ! in_array($value, $literals, true)) {
                    $unseen[] = $where;
                }
            }
        }

        self::assertSame([], $unseen, 'These constants hold an anchor the walk did not find written as a literal.');
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, int> anchor name => token index
     */
    private static function anchorsIn(array $tokens): array
    {
        $anchors = [];
        $function = null;

        foreach ($tokens as $index => $token) {
            if ($token->id === T_FUNCTION) {
                $next = self::significant($tokens, $index, 1);

                if ($next !== null && $tokens[$next]->id === T_STRING) {
                    $function = $tokens[$next]->text;
                }
            }

            if ($token->id !== T_CONSTANT_ENCAPSED_STRING || ! str_contains($token->text, '\z')) {
                continue;
            }

            $name = self::constantInitialisedBy($tokens, $index) ?? $function ?? 'outside any method';
            $key = $name;

            for ($n = 2; isset($anchors[$key]); $n++) {
                $key = sprintf('%s #%d', $name, $n);
            }

            $anchors[$key] = $index;
        }

        ksort($anchors, SORT_STRING);

        return $anchors;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $texts
     * @return class-string
     */
    private static function rebuiltWithout(array $tokens, array $texts, int $anchor, string $name): string
    {
        $texts[$anchor] = str_replace('\z', '$', $texts[$anchor]);

        foreach ($tokens as $index => $token) {
            $before = self::significant($tokens, $index, -1);

            // `Something::class` is a T_CLASS token too.
            if ($token->id !== T_CLASS || ($before !== null && $tokens[$before]->id === T_DOUBLE_COLON)) {
                continue;
            }

            $nameAt = self::significant($tokens, $index, 1);

            self::assertNotNull($nameAt);
            self::assertSame('EndpointPolicy', $tokens[$nameAt]->text);

            $texts[$nameAt] = sprintf('EndpointPolicyWithoutTheAnchorOf%s', substr(sha1(implode('', $texts)), 0, 12));
            $class = sprintf('Lynomia\\Modules\\Shared\\Domain\\Services\\%s', $texts[$nameAt]);

            if (! class_exists($class, false)) {
                $directory = sprintf('%s/endpoint-policy-anchors', sys_get_temp_dir());

                if (! is_dir($directory)) {
                    mkdir($directory, 0o700, true);
                }

                $path = sprintf('%s/%s.php', $directory, $texts[$nameAt]);
                file_put_contents($path, implode('', $texts));

                require_once $path;
            }

            self::assertTrue(class_exists($class, false), sprintf('The copy without the anchor of %s did not load.', $name));

            /** @var class-string $class */
            return $class;
        }

        self::fail('The policy file declares no class.');
    }

    /**
     * @param  class-string  $policyClass
     * @return list<string>
     */
    private function helpersThatNotice(string $policyClass): array
    {
        $noticed = [];

        foreach (self::KEEPERS as $helper) {
            try {
                $this->{$helper}($policyClass);
            } catch (AssertionFailedError) {
                $noticed[] = $helper;
            }
        }

        sort($noticed);

        return $noticed;
    }

    // ------------------------------------------------------------------
    // Small things
    // ------------------------------------------------------------------

    private function policy(): EndpointPolicy
    {
        return $this->app->make(EndpointPolicy::class);
    }

    /**
     * @param  class-string  $policyClass
     */
    private function policyBuiltFrom(string $policyClass): object
    {
        return new $policyClass($this->resolver());
    }

    private function resolver(): StaticHostResolver
    {
        $resolver = $this->app->make(HostResolver::class);

        self::assertInstanceOf(StaticHostResolver::class, $resolver, 'The suite resolves names through the test double bound in Tests\TestCase, never through the system resolver.');

        return $resolver;
    }

    private static function assertRefused(callable $attempt, string $reason, string $what): void
    {
        try {
            $attempt();
        } catch (EndpointRefused $refused) {
            self::assertStringContainsString($reason, $refused->getMessage(), sprintf('%s was refused, but not for the reason this row is about.', $what));

            return;
        }

        self::fail(sprintf('%s was accepted.', $what));
    }

    private static function urlFor(string $host): string
    {
        $bracketed = str_contains($host, ':') && ! str_starts_with($host, '[')
            ? sprintf('[%s]', $host)
            : $host;

        return sprintf('https://%s/', $bracketed);
    }

    /**
     * Every way inet_aton reads a dotted-quad: four, three, two and one parts,
     * each part in decimal, octal or hex.
     *
     * @return list<string>
     */
    private static function inetAtonSpellings(string $dotted): array
    {
        [$a, $b, $c, $d] = array_map(intval(...), explode('.', $dotted));

        $groupings = [
            [$a, $b, $c, $d],
            [$a, $b, ($c << 8) | $d],
            [$a, ($b << 16) | ($c << 8) | $d],
            [($a << 24) | ($b << 16) | ($c << 8) | $d],
        ];

        $spellings = [];

        foreach ($groupings as $parts) {
            $combinations = [[]];

            foreach ($parts as $part) {
                $next = [];

                foreach ($combinations as $prefix) {
                    foreach ([(string) $part, sprintf('0%o', $part), sprintf('0x%x', $part)] as $written) {
                        $next[] = [...$prefix, $written];
                    }
                }

                $combinations = $next;
            }

            foreach ($combinations as $combination) {
                $spellings[] = implode('.', $combination);
            }
        }

        return array_values(array_unique($spellings));
    }

    /**
     * @return list<string>
     */
    private static function decorated(string $spelling): array
    {
        return array_values(array_unique([
            $spelling,
            sprintf('%s.', $spelling),
            sprintf('%s:443', $spelling),
            strtoupper($spelling),
            str_replace('.', '。', $spelling),
            sprintf('[%s]', $spelling),
        ]));
    }

    /**
     * @return array<string, string>
     */
    private static function ipv6Embeddings(string $dotted): array
    {
        $packed = (string) inet_pton($dotted);
        $hex = bin2hex($packed);
        $high = ltrim(substr($hex, 0, 4), '0') ?: '0';
        $low = ltrim(substr($hex, 4, 4), '0') ?: '0';
        $obfuscated = bin2hex($packed ^ "\xff\xff\xff\xff");

        return [
            'IPv4-mapped, dotted' => sprintf('::ffff:%s', $dotted),
            'IPv4-mapped, hex' => sprintf('::ffff:%s:%s', $high, $low),
            'IPv4-mapped, upper case' => sprintf('::FFFF:%s:%s', strtoupper($high), strtoupper($low)),
            'IPv4-mapped, written long' => sprintf('0:0:0:0:0:ffff:%s:%s', $high, $low),
            'IPv4-compatible, dotted' => sprintf('::%s', $dotted),
            'IPv4-compatible, hex' => sprintf('::%s:%s', $high, $low),
            'SIIT' => sprintf('::ffff:0:%s:%s', $high, $low),
            'NAT64, dotted' => sprintf('64:ff9b::%s', $dotted),
            'NAT64, hex' => sprintf('64:ff9b::%s:%s', $high, $low),
            'NAT64 local use' => sprintf('64:ff9b:1::%s:%s', $high, $low),
            '6to4' => sprintf('2002:%s:%s::1', substr($hex, 0, 4), substr($hex, 4, 4)),
            'Teredo' => sprintf('2001:0:4136:e378:8000:63bf:%s:%s', substr($obfuscated, 0, 4), substr($obfuscated, 4, 4)),
        ];
    }

    /**
     * Every label of one byte, of two bytes, and `a<byte>a`.
     *
     * @return list<string>
     */
    private static function labelSpace(): array
    {
        if (self::$labelSpace !== null) {
            return self::$labelSpace;
        }

        $labels = [];

        for ($first = 0; $first < 256; $first++) {
            $labels[] = chr($first);

            for ($second = 0; $second < 256; $second++) {
                $labels[] = sprintf('%s%s', chr($first), chr($second));
            }

            $labels[] = sprintf('a%sa', chr($first));
        }

        return self::$labelSpace = $labels;
    }

    private static function dnsModuleAccepts(string $label): bool
    {
        if (array_key_exists($label, self::$dnsModuleAccepts)) {
            return self::$dnsModuleAccepts[$label];
        }

        $rule = new ReflectionMethod(DomainName::class, 'assertLabel');

        try {
            $rule->invoke(null, $label, $label);
            $accepted = true;
        } catch (InvalidDomainNameException) {
            $accepted = false;
        }

        return self::$dnsModuleAccepts[$label] = $accepted;
    }

    /**
     * @param  class-string  $class
     */
    private static function privateConstant(string $class, string $name): string
    {
        $value = (new ReflectionClassConstant($class, $name))->getValue();

        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function stringsIn(mixed $value, string $where): array
    {
        if (is_string($value)) {
            return [$where => $value];
        }

        if ($value instanceof UnitEnum) {
            self::fail(sprintf('%s holds an enum case. The string behind a case is declared in another file, where the walk does not look.', $where));
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $item) {
            $strings += self::stringsIn($item, sprintf('%s[%s]', $where, $key));
        }

        return $strings;
    }

    private static function singleQuoted(string $literal): string
    {
        return strtr(substr($literal, 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function significant(array $tokens, int $from, int $step): ?int
    {
        for ($at = $from + $step; isset($tokens[$at]); $at += $step) {
            if (! $tokens[$at]->isIgnorable()) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function constantInitialisedBy(array $tokens, int $literal): ?string
    {
        $equals = self::significant($tokens, $literal, -1);
        $name = $equals === null ? null : self::significant($tokens, $equals, -1);

        if ($equals === null || $name === null || $tokens[$equals]->text !== '=' || $tokens[$name]->id !== T_STRING) {
            return null;
        }

        for ($at = $name - 1; $at >= 0; $at--) {
            if ($tokens[$at]->id === T_CONST) {
                return $tokens[$name]->text;
            }

            if (in_array($tokens[$at]->text, [';', '{', '}'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * A `use` that imports, as opposed to a closure's `use (…)`.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function isAnImport(array $tokens, int $use): bool
    {
        $next = self::significant($tokens, $use, 1);

        return $next !== null && $tokens[$next]->text !== '(';
    }

    /**
     * Whether the import statement starting here imports a function, in any of
     * the three forms that can.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function importsAFunction(array $tokens, int $use): bool
    {
        for ($at = $use + 1; isset($tokens[$at]) && $tokens[$at]->text !== ';'; $at++) {
            if ($tokens[$at]->id === T_FUNCTION) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $compilers
     */
    private static function isACallTo(array $tokens, int $index, array $compilers): bool
    {
        $token = $tokens[$index];

        if (! in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return false;
        }

        $segments = explode('\\', $token->text);

        if (! in_array(strtolower((string) end($segments)), $compilers, true)) {
            return false;
        }

        $next = self::significant($tokens, $index, 1);
        $previous = self::significant($tokens, $index, -1);

        if ($next === null || $tokens[$next]->text !== '(') {
            return false;
        }

        return $previous === null || ! in_array($tokens[$previous]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function firstArgumentIsOneLiteralOrAConstant(array $tokens, int $callee): bool
    {
        $open = self::significant($tokens, $callee, 1);
        $argument = [];
        $depth = 0;

        for ($at = (int) $open + 1; isset($tokens[$at]); $at++) {
            $text = $tokens[$at]->text;

            if ($depth === 0 && ($text === ',' || $text === ')')) {
                break;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            if (! $tokens[$at]->isIgnorable()) {
                $argument[] = $tokens[$at];
            }
        }

        if (count($argument) === 1) {
            return $argument[0]->id === T_CONSTANT_ENCAPSED_STRING;
        }

        return count($argument) === 3
            && $argument[0]->id === T_STRING
            && in_array(strtolower($argument[0]->text), ['self', 'static'], true)
            && $argument[1]->id === T_DOUBLE_COLON
            && $argument[2]->id === T_STRING;
    }
}
