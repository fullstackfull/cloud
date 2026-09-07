<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Makefile is an entry point, and an entry point that names a file which
 * does not exist is a deployment that fails after somebody has already decided
 * to deploy.
 *
 * `make deploy-staging` named `playbooks/deploy-control-plane.yml` for the
 * whole of this build. The playbook is called `control-plane.yml`. Nothing
 * caught it, because nothing here runs Ansible and nobody reads a Makefile
 * looking for typos — which is exactly the kind of mistake a test is for.
 */
final class BuildEntryPointsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    /**
     * @return list<string>
     */
    private function makefileReferences(string $pattern): array
    {
        $makefile = file_get_contents(self::ROOT.'/Makefile');

        $this->assertIsString($makefile, 'The repository has no Makefile.');

        preg_match_all($pattern, $makefile, $matches);

        /** @var list<string> $found */
        $found = array_values(array_unique($matches[0]));

        return $found;
    }

    #[Test]
    public function every_playbook_the_makefile_runs_exists(): void
    {
        $playbooks = $this->makefileReferences('#playbooks/[a-z0-9-]+\.yml#');

        $this->assertNotEmpty($playbooks, 'No playbook references found; this test is checking nothing.');

        foreach ($playbooks as $playbook) {
            $this->assertFileExists(
                self::ROOT.'/infrastructure/ansible/'.$playbook,
                sprintf('The Makefile runs %s, which does not exist.', $playbook),
            );
        }
    }

    #[Test]
    public function every_inventory_the_makefile_names_exists(): void
    {
        $makefile = file_get_contents(self::ROOT.'/Makefile');
        $this->assertIsString($makefile);

        // Only the literal ones: `inventories/$(ENV)` is resolved at call time
        // and its possible values are the directories themselves.
        preg_match_all('#inventories/(?!\$)([a-z0-9-]+)#', $makefile, $matches);

        foreach (array_unique($matches[1]) as $inventory) {
            $this->assertDirectoryExists(
                self::ROOT.'/infrastructure/ansible/inventories/'.$inventory,
                sprintf('The Makefile targets the %s inventory, which does not exist.', $inventory),
            );
        }
    }
}
