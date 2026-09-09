<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOrder;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Deployment\AnsibleDeploymentController;
use Lynomia\Modules\Infrastructure\Infrastructure\Deployment\DeploymentControllerFactory;
use Lynomia\Modules\Infrastructure\Infrastructure\Deployment\FakeDeploymentController;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bridge to the machines, tested for what it refuses and what it would
 * run. Nothing here executes ansible: the process runner is replaced, and
 * the argument list it would have been given is what is asserted.
 */
final class TheBridgeRefusesToRunWhereItMustNotTest extends TestCase
{
    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = sys_get_temp_dir().'/lynomia-iac-'.uniqid();
        mkdir($this->tree.'/ansible/playbooks', 0777, true);
        file_put_contents($this->tree.'/ansible/playbooks/redis.yml', "---\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tree.'/ansible/playbooks/redis.yml');
        @rmdir($this->tree.'/ansible/playbooks');
        @rmdir($this->tree.'/ansible');
        @rmdir($this->tree);

        parent::tearDown();
    }

    private function order(string $host = 'redis-01', string $playbook = 'redis.yml'): DeploymentOrder
    {
        return new DeploymentOrder(
            jobId: '01JDEPLOY',
            kind: DeploymentKind::Apply,
            host: $host,
            managementAddress: '10.66.0.10',
            environment: DeploymentEnvironment::Staging,
            playbook: $playbook,
            roles: ['common', 'hardening', 'redis'],
            configuration: ['common' => [], 'hardening' => ['ssh_port' => '2222'], 'redis' => ['maxmemory' => '1gb']],
            verifications: ['common' => null, 'hardening' => 'service:fail2ban', 'redis' => 'service:redis-server'],
            timeoutSeconds: 60,
        );
    }

    /**
     * @param  array{exit: int, output: string, timedOut: bool}  $result
     */
    private function controller(array $result, bool $ci = false, ?string $tree = null, ?array &$seen = null): AnsibleDeploymentController
    {
        return new AnsibleDeploymentController($tree ?? $this->tree, $ci, function (array $command, string $cwd, int $timeout) use ($result, &$seen): array {
            $seen = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];

            return $result;
        });
    }

    #[Test]
    public function the_fake_refuses_to_exist_in_production(): void
    {
        $this->expectException(DeploymentRefused::class);

        new FakeDeploymentController('production');
    }

    #[Test]
    public function the_factory_builds_only_the_two_drivers_it_knows(): void
    {
        $this->assertSame('fake', (new DeploymentControllerFactory('fake', 'testing', null, false))->make()->driver());
        $this->assertSame('ansible', (new DeploymentControllerFactory('ansible', 'production', $this->tree, false))->make()->driver());

        $this->expectException(DeploymentRefused::class);
        (new DeploymentControllerFactory('ssh-and-hope', 'testing', null, false))->make();
    }

    #[Test]
    public function the_ansible_bridge_refuses_in_ci(): void
    {
        $this->expectException(DeploymentRefused::class);
        $this->expectExceptionMessage('CI validates infrastructure');

        $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false], ci: true)->apply($this->order());
    }

    #[Test]
    public function the_ansible_bridge_refuses_without_the_tree_or_the_playbook(): void
    {
        try {
            $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false], tree: '/nowhere')->apply($this->order());
            $this->fail('ran without a tree');
        } catch (DeploymentRefused $refused) {
            $this->assertStringContainsString('INFRASTRUCTURE_IAC_PATH', $refused->getMessage());
        }

        try {
            $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false])->apply($this->order(playbook: 'reimage-everything.yml'));
            $this->fail('ran a playbook not in the tree');
        } catch (DeploymentRefused $refused) {
            $this->assertStringContainsString('not in the tree', $refused->getMessage());
        }

        try {
            $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false])->apply($this->order(playbook: '../../etc/passwd.yml'));
            $this->fail('ran a path');
        } catch (DeploymentRefused $refused) {
            $this->assertStringContainsString('not in the tree', $refused->getMessage());
        }
    }

    #[Test]
    public function the_host_is_a_hostname_and_never_an_argument(): void
    {
        $this->expectException(DeploymentRefused::class);

        $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false])->apply($this->order(host: 'redis-01 --become'));
    }

    #[Test]
    public function the_command_is_an_argument_list_limited_to_one_host_with_variables_from_a_file(): void
    {
        $seen = null;
        $outcome = $this->controller(['exit' => 0, 'output' => 'PLAY RECAP', 'timedOut' => false], seen: $seen)->apply($this->order());

        $this->assertTrue($outcome->succeeded);
        $this->assertSame($this->tree.'/ansible', $seen['cwd']);
        $this->assertSame(60, $seen['timeout']);

        $command = $seen['command'];
        $this->assertSame('ansible-playbook', $command[0]);
        $this->assertSame(['-i', 'inventories/staging', 'playbooks/redis.yml', '--limit', 'redis-01'], array_slice($command, 1, 5));
        $this->assertSame('--extra-vars', $command[6]);
        $this->assertStringStartsWith('@', $command[7]);
        $this->assertNotContains('--check', $command);

        // No override value appears in argv; they went through the file.
        $this->assertStringNotContainsString('2222', implode(' ', $command));
        $this->assertStringNotContainsString('1gb', implode(' ', $command));
    }

    #[Test]
    public function verification_is_the_same_playbook_in_check_mode_and_claims_presence_only_from_a_clean_run(): void
    {
        $seen = null;
        $outcome = $this->controller(['exit' => 0, 'output' => '', 'timedOut' => false], seen: $seen)->verify($this->order());

        $this->assertContains('--check', $seen['command']);
        $this->assertContains('--diff', $seen['command']);
        $this->assertSame('true', $outcome->facts['software.redis.present']);
        $this->assertSame('true', $outcome->facts['software.common.present']);
    }

    #[Test]
    public function a_run_that_outlives_its_deadline_is_indeterminate_not_failed(): void
    {
        $outcome = $this->controller(['exit' => -1, 'output' => '', 'timedOut' => true])->apply($this->order());

        $this->assertFalse($outcome->succeeded);
        $this->assertTrue($outcome->indeterminate);
        $this->assertSame(FailureClass::Timeout, $outcome->failureClass);
        $this->assertStringContainsString('part-way through', (string) $outcome->detail);
    }

    #[Test]
    public function an_unreachable_host_is_transient_and_a_failed_playbook_is_permanent(): void
    {
        $this->assertSame(FailureClass::Transient, $this->controller(['exit' => 4, 'output' => '', 'timedOut' => false])->apply($this->order())->failureClass);
        $this->assertSame(FailureClass::Permanent, $this->controller(['exit' => 2, 'output' => '', 'timedOut' => false])->apply($this->order())->failureClass);
    }
}
