<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Infrastructure\Domain\Naming\DnsSuffix;
use Lynomia\Modules\Infrastructure\Domain\Naming\NameKind;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * One authority decides what this platform's hosts are called.
 *
 * ===========================================================================
 * THE STATE THIS GATE ENDS
 * ===========================================================================
 *
 * This repository carried two naming families and nobody had chosen between
 * them. The Ansible inventories named example hosts `cp-1.prod.example`, under
 * a TLD IANA reserves for documents. The monitoring stack named the same
 * estate `cp-app-01.kw.lynomia.internal` — a different family, a different
 * spelling of the same machines, and a zone that reads as somebody's real
 * internal network.
 *
 * The second one was worse than untidy. `EndpointPolicy::FORBIDDEN_SUFFIXES`
 * has refused `.internal` in every mode since Gap 2, because names under it
 * are "this host, this network or a metadata service". So the application
 * could never have been configured to talk to the hosts its own monitoring
 * configuration named, and the repository asserted two incompatible things
 * about one estate.
 *
 * ===========================================================================
 * WHAT THIS GATE CHECKS, AND WHERE IT DELIBERATELY DOES NOT LOOK
 * ===========================================================================
 *
 * It reads values in positions where a hostname is a hostname: target lists,
 * alert annotation URLs, the external URLs the monitoring stack publishes, and
 * the example inventories' host keys. Every one of those must be under a
 * domain reserved for examples, because the real ones arrive at deploy time
 * from a private inventory.
 *
 * It does not grep the repository for the text. Historical reports describe
 * the old state and must go on describing it: `docs/` is where this gap's own
 * findings are written down, and a gate that banned the string would make the
 * report of the problem a violation of the fix. §61 asks for a gate that
 * understands context, and the way to understand context is to read values
 * rather than files.
 */
final class OneNamingAuthorityTest extends TestCase
{
    /**
     * Host-shaped values that are not hosts.
     *
     * Kept short on purpose: a long allow-list is a gate that has stopped
     * asserting anything.
     *
     * @var list<string>
     */
    private const array NOT_A_HOST = [
        'localhost',
        // Compose service names, resolved by the container network rather than
        // by DNS. They have no zone and cannot be mistaken for one.
        'prometheus',
        'alertmanager',
        'grafana',
        'loki',
        'alloy',
        'blackbox-exporter',
        'postgres-exporter',
        'redis-exporter',
        'node-exporter',
        'pve-exporter',
    ];

    private ReferenceValues $reference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reference = new ReferenceValues;
    }

    // -----------------------------------------------------------------------
    // The application
    // -----------------------------------------------------------------------

    #[Test]
    public function no_naming_authority_is_hardcoded_in_application_source(): void
    {
        /*
         * Both historical families, and the product's own public domain. Any
         * of the three in executable source would be this repository deciding
         * what an operator's estate is called — which is the one decision Gap 5
         * says code does not get to make.
         *
         * Comments are stripped first, for the reason Gap 4's gate established:
         * a docblock naming the old scheme while explaining why it is gone is
         * documentation, and a gate that flagged it would be measuring prose.
         */
        $forbidden = ['lynomia.internal', 'prod.example', 'dev.example', 'lynomia.com'];
        $offences = [];

        foreach ($this->applicationFiles() as $file) {
            $code = $this->codeOnly($file->getRealPath());

            foreach ($forbidden as $literal) {
                if (str_contains($code, $literal)) {
                    $offences[] = sprintf('%s names %s', $this->relative($file->getRealPath()), $literal);
                }
            }
        }

        self::assertSame([], $offences, implode("\n", [
            'A DNS naming authority is hardcoded in application source.',
            'The zone this platform\'s hosts live under is an operator\'s decision, read through',
            'config(\'infrastructure.naming.internal_dns_suffix\'). A literal here means supplying real',
            'values requires a source edit, and it means two schemes again the moment somebody',
            'disagrees with the literal.',
            ...$offences,
        ]));
    }

    #[Test]
    public function exactly_one_class_reads_the_configured_dns_suffix_and_exactly_one_file_defines_it(): void
    {
        /*
         * Two assertions about one value, because there are two ways to end up
         * with two answers to "what zone are we in": a second class reading the
         * config key with its own rule about production, or a second config
         * entry reading the same environment variable under a different name.
         *
         * The audit service and the command name the environment variable in
         * the sentences they show an operator. That is the variable somebody
         * has to go and set, and quoting it is the next action; it is not a
         * second reader, because neither one asks the environment for it.
         */
        $keyReaders = [];
        $definitions = [];

        foreach ($this->applicationFiles() as $file) {
            $path = $file->getRealPath();

            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $code = $this->codeOnly($path);

            if (str_contains($code, "'infrastructure.naming.internal_dns_suffix'")) {
                $keyReaders[] = $this->relative($path);
            }

            if (str_contains($code, "env('INFRASTRUCTURE_INTERNAL_DNS_SUFFIX')")) {
                $definitions[] = $this->relative($path);
            }
        }

        self::assertSame(
            ['apps/control-plane/src/Modules/Infrastructure/Domain/Naming/DnsSuffix.php'],
            $keyReaders,
            implode("\n", [
                'The configured DNS suffix must have exactly one reader.',
                'Everything else asks InfrastructureNamingPolicy, which asks DnsSuffix. A second reader is a',
                'second policy about what a valid production zone is, and one of them will be wrong about',
                'the reference domains.',
                ...$keyReaders,
            ]),
        );

        self::assertSame(
            ['apps/control-plane/config/infrastructure.php'],
            $definitions,
            implode("\n", [
                'The environment variable behind the DNS suffix must be read in exactly one place.',
                'env() outside a config file is read once at boot and then frozen by a cached',
                'configuration, which is how a value an operator changed goes on being the old one.',
                ...$definitions,
            ]),
        );
    }

    #[Test]
    public function no_stable_identity_is_carried_by_a_hostname_field(): void
    {
        /*
         * The conflation this standard exists to prevent, stated against the
         * catalogue that declares it. A logical key is referenced by orders,
         * audit history, deployment plans and metric series; a hostname changes
         * when DNS does. Declaring a hostname column as an identity is how a
         * network change orphans a customer's order.
         */
        $offences = [];

        foreach (NamingConcept::cases() as $concept) {
            $isHostnameColumn = in_array($concept->column(), ['hostname', 'fqdn', 'address', 'endpoint'], strict: true);

            if ($isHostnameColumn && $concept->kind() !== NameKind::NetworkName) {
                $offences[] = sprintf(
                    '%s is a %s carried by the column %s, which holds a network name',
                    $concept->value,
                    $concept->kind()->label(),
                    $concept->column(),
                );
            }

            if (! $isHostnameColumn && $concept->kind() === NameKind::NetworkName) {
                $offences[] = sprintf('%s is declared a hostname but its column is %s', $concept->value, $concept->column());
            }
        }

        self::assertSame([], $offences, implode("\n", [
            'A hostname is being treated as an identity, or an identity as a hostname.',
            'Stable identity, network identity, display name and provider-native id are four',
            'different things — see docs/infrastructure-naming-standard.md.',
            ...$offences,
        ]));
    }

    // -----------------------------------------------------------------------
    // The committed infrastructure configuration
    // -----------------------------------------------------------------------

    #[Test]
    public function every_host_named_in_a_monitoring_target_list_is_a_reference_host(): void
    {
        $hosts = [];

        foreach (glob($this->infrastructure('monitoring/prometheus/targets/*.yml')) ?: [] as $path) {
            foreach ($this->lines($path) as $line) {
                // Target entries only: `    - host:9100` under a targets: key.
                if (preg_match('/^\s+-\s+(\S+)\s*$/', $line, $match) !== 1) {
                    continue;
                }

                $hosts[$this->relative($path)][] = $match[1];
            }
        }

        self::assertNotSame([], $hosts, 'No target list was read, so this gate would pass vacuously.');

        $this->assertEveryHostIsReserved($hosts, 'a monitoring target list');
    }

    #[Test]
    public function every_url_a_monitoring_rule_or_the_stack_itself_publishes_is_a_reference_url(): void
    {
        $hosts = [];

        foreach (glob($this->infrastructure('monitoring/prometheus/rules/*.yml')) ?: [] as $path) {
            foreach ($this->lines($path) as $line) {
                if (preg_match('/(?:runbook_url|dashboard_url):\s*(\S+)/', $line, $match) === 1) {
                    $hosts[$this->relative($path)][] = $match[1];
                }
            }
        }

        foreach ($this->lines($this->infrastructure('monitoring/docker-compose.monitoring.yml')) as $line) {
            if (preg_match('/(?:--web\.external-url=|_ROOT_URL:\s*)(\S+)/', $line, $match) === 1) {
                $hosts[$this->relative($this->infrastructure('monitoring/docker-compose.monitoring.yml'))][] = $match[1];
            }
        }

        self::assertNotSame([], $hosts, 'No URL was read, so this gate would pass vacuously.');

        $this->assertEveryHostIsReserved($hosts, 'a monitoring URL');
    }

    #[Test]
    public function every_host_in_an_example_inventory_is_a_reference_host(): void
    {
        $hosts = [];

        foreach (glob($this->infrastructure('ansible/inventories/*/hosts.yml')) ?: [] as $path) {
            foreach ($this->lines($path) as $line) {
                // A host key: eight spaces of indentation and a trailing colon,
                // which is how these inventories write a host and nothing else.
                if (preg_match('/^ {8}(\S+):\s*$/', $line, $match) === 1) {
                    $hosts[$this->relative($path)][] = $match[1];
                }
            }
        }

        self::assertNotSame([], $hosts, 'No inventory host was read, so this gate would pass vacuously.');

        $this->assertEveryHostIsReserved($hosts, 'an example inventory');
    }

    #[Test]
    public function every_endpoint_in_an_iac_example_is_a_reference_endpoint(): void
    {
        $hosts = [];

        $finder = (new Finder)
            ->files()
            ->name(['*.tfvars.example', '*.yml.example'])
            ->in($this->infrastructure(''));

        foreach ($finder as $file) {
            foreach ($this->lines($file->getRealPath()) as $line) {
                if (preg_match('#=\s*"(https?://[^"]+)"#', $line, $match) === 1) {
                    $hosts[$this->relative($file->getRealPath())][] = $match[1];
                }
            }
        }

        self::assertNotSame([], $hosts, 'No IaC example endpoint was read, so this gate would pass vacuously.');

        $this->assertEveryHostIsReserved($hosts, 'an infrastructure-as-code example');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, list<string>>  $byFile
     */
    private function assertEveryHostIsReserved(array $byFile, string $context): void
    {
        $offences = [];

        foreach ($byFile as $file => $values) {
            foreach ($values as $value) {
                $host = $this->hostOf($value);

                if ($host === null) {
                    continue;
                }

                if ($this->reference->isDocumentationHostname($host)) {
                    continue;
                }

                $offences[] = sprintf('%s names %s', $file, $host);
            }
        }

        self::assertSame([], $offences, implode("\n", [
            sprintf('A host named in %s is not a reference host.', $context),
            'Every name committed to this repository is an example: the real ones arrive at deploy',
            'time from the private inventory. A plausible-looking internal hostname here is one',
            'copy-paste from being configured as real, and it is how this repository came to hold two',
            'naming schemes that disagreed with each other and with EndpointPolicy.',
            'Use the reserved namespace (.example), or make the value a variable the operator supplies.',
            ...$offences,
        ]));
    }

    /**
     * The host inside a target, a URL or a compose variable, or null when the
     * value does not name a host at all.
     */
    private function hostOf(string $value): ?string
    {
        // `${VAR:-https://prometheus.prod.example}`: the default is the part
        // this repository is asserting, so that is the part to judge.
        if (preg_match('/^\$\{[A-Z_]+:-(.+)\}$/', $value, $match) === 1) {
            $value = $match[1];
        }

        // A variable with no default names nothing, which is the outcome this
        // standard prefers for an operator-owned value.
        if (str_starts_with($value, '${')) {
            return null;
        }

        $host = (string) preg_replace('#^[a-z]+://#', '', $value);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        $host = strtolower(trim($host));

        if ($host === '' || in_array($host, self::NOT_A_HOST, strict: true)) {
            return null;
        }

        // An address is not a name. Documentation ranges are the inventory
        // validators' subject and they check every one of them.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $name = DnsName::tryFrom($host);

        return $name !== null && $name->isFullyQualified() ? $name->value() : null;
    }

    /** @return list<string> */
    private function lines(string $path): array
    {
        $contents = @file_get_contents($path);

        return $contents === false ? [] : explode("\n", $contents);
    }

    private function infrastructure(string $path): string
    {
        return base_path('../../infrastructure/'.$path);
    }

    private function relative(string $path): string
    {
        $root = (string) realpath(base_path('../..'));

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/') : $path;
    }

    private function codeOnly(string $path): string
    {
        $contents = (string) file_get_contents($path);

        if (! str_ends_with($path, '.php')) {
            // TypeScript has no tokeniser here; its comments are stripped
            // line-wise, which is coarse and errs towards flagging rather than
            // towards missing something.
            return (string) preg_replace(['#^\s*//.*$#m', '#/\*.*?\*/#s'], '', $contents);
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function applicationFiles(): iterable
    {
        $finder = (new Finder)
            ->files()
            ->name(['*.php', '*.ts', '*.tsx'])
            ->notPath('vendor')
            ->notPath('node_modules')
            ->notPath('tests')
            ->notPath('database/seeders')
            ->notPath('resources/reference-topology')
            ->in([base_path('src'), base_path('app'), base_path('config')]);

        yield from $finder;

        yield from (new Finder)
            ->files()
            ->name(['*.ts', '*.tsx'])
            ->notPath('node_modules')
            // Regex form deliberately: Symfony's Finder reads a string whose
            // first and last characters are the same non-alphanumeric as a
            // regex, so `__tests__` would become the pattern `tests` with
            // modifiers and warn about an unknown modifier `t`.
            ->notPath('#/__tests__/#')
            ->in(base_path('../web/src'));
    }

    #[Test]
    public function the_suffix_value_object_still_refuses_a_reference_zone_for_production(): void
    {
        // The gate above proves no literal is in the source. This proves the
        // configuration path cannot be pointed at one either, which is the
        // other half of "a production deployment can never inherit the
        // reference estate's names".
        self::assertNotNull(DnsSuffix::problemWith('dc1.reference.example', production: true));
        self::assertNotNull(DnsSuffix::problemWith('prod.example', production: true));
        self::assertNull(DnsSuffix::problemWith('dc1.operator-chosen-zone.net', production: true));
    }
}
