<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the repository says about log shipping agrees with what it deploys.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * Nothing under `infrastructure/ansible` installs Grafana Alloy on an
 * application host, so the structured log reaches Loki by no route at all.
 * That gap is recorded rather than closed here: on a control-plane host this
 * tree installs no agent (the control_plane role opens 9100 for node_exporter
 * and installs none, though the proxmox role does install it on hypervisors),
 * and a role nobody can execute offline, added to a play that runs against
 * production, risks a working deploy to fix an observability gap.
 *
 * What made the gap dangerous was not its existence but the sentences denying
 * it. `config/logging.php` said "Grafana Alloy tails this file and ships it to
 * Loki"; the compose file said an Alloy on each application host was "deployed
 * by the Ansible monitoring role". Both false, and both exactly what would stop
 * the next reader from looking. A false sentence about a pipeline is worse than
 * a missing one.
 *
 * ===========================================================================
 * HOW IT IS HELD
 * ===========================================================================
 *
 * The fact is declared once, as a variable: `lynomia_log_shipper_deployed` in
 * `group_vars/all.yml`. Every file that talks about shipping carries a
 * `SHIPPER-STATUS:` marker, and the markers must agree with the variable. The
 * variable itself is checked in the one direction the tree can check: while it
 * says `false`, nothing under `infrastructure/ansible` may install or run a
 * shipper. `true` with nothing here installing one is legitimate — that is the
 * likely shape of the fix, an agent installed out-of-band — so that direction
 * is not asserted. When the gap closes, flip the variable and the markers
 * together; nothing in this file asks to be deleted.
 *
 * ===========================================================================
 * WHERE ITS EDGES ARE
 * ===========================================================================
 *
 * The false-claim check matches the sentences that were actually in the tree,
 * kept verbatim only here. It catches their restoration, not a paraphrase:
 * the fact guarded by the markers is the agreement between prose and a
 * deployment fact, and the sentences are the regression net under it.
 *
 * The install check reads `infrastructure/ansible` only. The monitoring
 * compose stack legitimately runs Alloy — on the monitoring host, for that
 * host's journal, with no release tree mounted — and widening the root would go
 * red against a true declaration on the first run. It matches four shapes that
 * mean install-or-run, after stripping comments, so a task title or a comment
 * that mentions Alloy is not an installation. It can miss an exotic shape
 * (false negatives), and it is built not to fire on prose. It reads one line
 * at a time, so a module argument naming the package fires whatever the
 * task's `state:` says: a task removing Alloy is flagged too.
 */
final class TheLogShipperDeclarationIsTrueTest extends TestCase
{
    private const string GROUP_VARS = '/infrastructure/ansible/group_vars/all.yml';

    private const string ANSIBLE = '/infrastructure/ansible';

    /**
     * Every file that says something about whether the application log ships.
     *
     * @var list<string>
     */
    private const array MARKED = [
        '/apps/control-plane/config/logging.php',
        '/apps/control-plane/src/Modules/Shared/Infrastructure/Logging/StructuredLogger.php',
        '/infrastructure/monitoring/docker-compose.monitoring.yml',
        '/docs/monitoring.md',
        '/docs/production-checklist.md',
    ];

    /**
     * The sentences that were in the tree while nothing shipped the log.
     *
     * @var list<string>
     */
    private const array FALSE_WHILE_UNDEPLOYED = [
        'Grafana Alloy tails this file and ships it to Loki',
        'Builds the structured JSON channel that Grafana Alloy ships to Loki',
        'is shipped by an Alloy running on each application host, deployed by the Ansible monitoring role',
        'Grafana Alloy is shipping it',
    ];

    #[Test]
    public function the_deployment_of_a_log_shipper_is_declared(): void
    {
        $this->assertIsBool(
            $this->declared(),
            'group_vars/all.yml must declare lynomia_log_shipper_deployed: true or false.',
        );
    }

    #[Test]
    public function every_file_that_talks_about_shipping_agrees_with_the_declaration(): void
    {
        $expected = $this->declared() ? 'deployed' : 'not deployed';

        foreach (self::MARKED as $relative) {
            $text = $this->read($relative);

            $this->assertSame(
                1,
                preg_match_all('/SHIPPER-STATUS:\s*(not deployed|deployed)\b/', $text, $found),
                sprintf('%s must carry exactly one SHIPPER-STATUS marker, so the next reader of that file learns whether its log actually ships.', $relative),
            );
            $this->assertSame(
                $expected,
                $found[1][0],
                sprintf(
                    '%s says SHIPPER-STATUS: %s, and group_vars/all.yml says lynomia_log_shipper_deployed: %s. Change them together.',
                    $relative,
                    $found[1][0],
                    $this->declared() ? 'true' : 'false',
                ),
            );
        }
    }

    #[Test]
    public function nothing_states_as_fact_that_the_application_log_is_being_shipped(): void
    {
        if ($this->declared()) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($this->filesThatMightClaimIt() as $relative) {
            $prose = $this->prose($this->read($relative));

            foreach (self::FALSE_WHILE_UNDEPLOYED as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $prose,
                    sprintf(
                        "%s states \"%s\", and lynomia_log_shipper_deployed is false.\n"
                        .'A false sentence about a pipeline is worse than a missing one: it is what stops the next reader from discovering the gap.',
                        $relative,
                        $claim,
                    ),
                );
            }
        }
    }

    #[Test]
    public function while_no_shipper_is_declared_nothing_in_ansible_installs_or_runs_one(): void
    {
        if ($this->declared()) {
            $this->addToAssertionCount(1);

            return;
        }

        $offending = $this->ansibleLinesThatDeployTheShipper();

        $this->assertSame(
            [],
            $offending,
            "lynomia_log_shipper_deployed is false, and these lines install or run a log shipper:\n  "
            .implode("\n  ", $offending)
            ."\n\nIf the gap is closed, set lynomia_log_shipper_deployed: true and change every SHIPPER-STATUS marker with it.",
        );
    }

    #[Test]
    public function the_install_check_recognises_each_shape_it_claims_to(): void
    {
        /*
         * The oracle proved on the shapes it names, and on the two it must not
         * take: a task title and a comment.
         */
        $deploys = [
            "    name: alloy\n",
            "    name: grafana-alloy\n",
            "  - role: grafana-alloy\n",
            "  - grafana.grafana.alloy\n",
            "    cmd: apt-get install -y alloy\n",
            "    ansible.builtin.command: alloy run /etc/alloy/config.alloy\n",
            "    cmd: systemctl enable --now alloy\n",
        ];
        $prose = [
            "- name: Grafana Alloy is not installed on this host\n",
            "# nothing here installs Grafana Alloy\n",
            "    name: lynomia-horizon  # alloy lives elsewhere\n",
        ];

        foreach ($deploys as $line) {
            $this->assertNotSame([], $this->deployingLines('probe.yml', $line), 'Not recognised as deploying a shipper: '.trim($line));
        }
        foreach ($prose as $line) {
            $this->assertSame([], $this->deployingLines('probe.yml', $line), 'Prose mistaken for deploying a shipper: '.trim($line));
        }
    }

    private function declared(): bool
    {
        $this->assertSame(
            1,
            preg_match('/^lynomia_log_shipper_deployed:\s*(true|false)\s*(?:#.*)?$/m', $this->read(self::GROUP_VARS), $match),
            'group_vars/all.yml must declare lynomia_log_shipper_deployed: true or false.',
        );

        return $match[1] === 'true';
    }

    /**
     * Configuration, source and documentation — not the audit record, which
     * quotes the false sentences in order to say they were false.
     *
     * @return list<string>
     */
    private function filesThatMightClaimIt(): array
    {
        $root = dirname(__DIR__, 4);
        $found = self::MARKED;

        foreach (['/apps/control-plane/config', '/apps/control-plane/src', '/infrastructure', '/docs'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.$directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $relative = substr($file->getPathname(), strlen($root));

                if (! preg_match('/\.(php|ya?ml|md|alloy|j2)$/', $relative)
                    || str_starts_with($relative, '/docs/round-2')
                    || str_starts_with($relative, '/docs/final-independent')) {
                    continue;
                }

                $found[] = $relative;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Collapse comment markers and line breaks so a sentence wrapped across a
     * docblock or a YAML comment is matched as the sentence it reads as.
     */
    private function prose(string $text): string
    {
        $text = (string) preg_replace('/^\s*(?:\/\*\*?|\*\/?|#|\/\/)\s?/m', '', $text);

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * @return list<string>
     */
    private function ansibleLinesThatDeployTheShipper(): array
    {
        $root = dirname(__DIR__, 4);
        $this->assertDirectoryExists($root.self::ANSIBLE);

        $offending = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.self::ANSIBLE, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! preg_match('/\.(ya?ml|j2|sh|cfg|ini|service)$/', $file->getFilename())) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            array_push($offending, ...$this->deployingLines($relative, (string) file_get_contents($file->getPathname())));
        }

        sort($offending);

        return $offending;
    }

    /**
     * Lines of one file that install or run Alloy.
     *
     * Four shapes, after stripping comments:
     *   1. a module argument naming the package or unit (`name: alloy`,
     *      `pkg: grafana-alloy`) — indented and without a list dash, so a
     *      task title (`- name: Install Alloy`) is not one;
     *   2. a role applied by name (`- role: grafana-alloy`,
     *      `- grafana.grafana.alloy`);
     *   3. a package manager installing it;
     *   4. the binary run, or its unit enabled or started.
     *
     * @return list<string>
     */
    private function deployingLines(string $relative, string $contents): array
    {
        $shapes = [
            '/^\s+(?:name|pkg|package|deb|service|unit|image):\s*["\']?[a-z0-9_.\/-]*alloy[a-z0-9_.-]*["\']?\s*$/i',
            '/^\s*-\s*(?:role:\s*)?["\']?[a-z0-9_.\/-]*alloy[a-z0-9_.-]*["\']?\s*$/i',
            '/\b(?:apt(?:-get)?|dnf|yum|snap|zypper)\s+install\b.*\balloy\b/i',
            '/\balloy\s+run\b|\bsystemctl\s+(?:enable|start|restart)\b.*\balloy\b/i',
        ];

        $offending = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $number => $raw) {
            $line = (string) preg_replace('/(?:^|\s)#.*$/', '', $raw);

            foreach ($shapes as $shape) {
                if (preg_match($shape, $line) === 1) {
                    $offending[] = sprintf('%s:%d  %s', $relative, $number + 1, trim($raw));
                    break;
                }
            }
        }

        return $offending;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 4).$relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
