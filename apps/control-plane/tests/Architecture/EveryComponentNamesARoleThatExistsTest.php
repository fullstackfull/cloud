<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The software catalogue names Ansible roles and playbooks. Each must exist
 * in infrastructure/ansible, or a plan would promise a change nothing can
 * make — the exact shape of failure this phase exists to catch, one level
 * down from the provider catalogue's version of it.
 */
final class EveryComponentNamesARoleThatExistsTest extends TestCase
{
    private const string IAC = __DIR__.'/../../../../infrastructure/ansible';

    #[Test]
    public function every_component_names_a_role_in_the_tree(): void
    {
        $missing = [];

        foreach ((new SoftwareCatalogue)->components() as $component) {
            if (! is_file(self::IAC.'/roles/'.$component->ansibleRole.'/tasks/main.yml')) {
                $missing[] = sprintf('%s → roles/%s', $component->key, $component->ansibleRole);
            }
        }

        $this->assertSame([], $missing, "Components naming roles nobody wrote:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function every_profile_names_a_playbook_in_the_tree_and_only_components_that_exist(): void
    {
        $catalogue = new SoftwareCatalogue;
        $problems = [];

        foreach ($catalogue->profiles() as $profile) {
            if (! is_file(self::IAC.'/playbooks/'.$profile->playbook)) {
                $problems[] = sprintf('%s → playbooks/%s is missing', $profile->key, $profile->playbook);
            }

            foreach ($profile->components as $key) {
                if ($catalogue->component($key) === null) {
                    $problems[] = sprintf('%s lists component %s, which the catalogue does not define', $profile->key, $key);
                }
            }

            // Dependencies before dependents, in the order the plan will run.
            $seen = [];
            foreach ($catalogue->componentsOf($profile) as $component) {
                foreach ($component->dependsOn as $dependency) {
                    if (! isset($seen[$dependency])) {
                        $problems[] = sprintf('%s installs %s before its dependency %s', $profile->key, $component->key, $dependency);
                    }
                }
                $seen[$component->key] = true;
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function no_component_declares_an_override_that_could_carry_a_shell_or_template(): void
    {
        foreach ((new SoftwareCatalogue)->components() as $component) {
            foreach ($component->accepts as $key) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,40}$/', $key, "{$component->key} accepts an override with a suspicious name: {$key}");
            }
        }
    }
}
