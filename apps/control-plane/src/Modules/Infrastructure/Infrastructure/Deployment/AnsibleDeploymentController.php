<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Deployment;

use Lynomia\Modules\Infrastructure\Domain\Contracts\DeploymentController;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOrder;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOutcome;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The bridge to the reviewed playbooks in infrastructure/ansible.
 *
 * Runs on the deployment controller host — the only thing that crosses into
 * the management network — and nowhere else: it refuses when CI is in the
 * environment, when the IaC tree is not where configuration says, and when
 * the order names a playbook that is not in the tree. It never builds a
 * shell string: the command is an argument list, the host is the machine's
 * registered name limited by `--limit`, and the only variables handed over
 * are the validated overrides, written to a temporary JSON file and passed
 * with `--extra-vars @file`. Nothing an operator typed reaches argv.
 *
 * Verification is the same playbook in `--check --diff`, which the tree's
 * own guard treats as a non-apply, plus the recap. A component's declared
 * `service:`/`port:` verification is a fact the check run's role asserts;
 * this driver reports presence from a clean check-mode recap and nothing
 * else, and says so in the step.
 *
 * A run that outlives its deadline is reported indeterminate — the process
 * is killed, but the playbook may have got half-way — and nothing here or
 * above retries it.
 *
 * This driver has never been run against a real machine from this control
 * plane. What is tested is what it refuses and what it would run.
 */
final readonly class AnsibleDeploymentController implements DeploymentController
{
    private const string HOST_SHAPE = '/^[a-z0-9][a-z0-9.-]{0,119}$/i';

    private const string PLAYBOOK_SHAPE = '/^[a-z0-9-]+\.yml$/';

    /**
     * @param  callable(list<string>, string, int): array{exit: int, output: string, timedOut: bool}|null  $runner  Replaced in tests; the default runs the process.
     */
    public function __construct(
        private ?string $iacPath,
        private bool $ci,
        private mixed $runner = null,
    ) {}

    public function driver(): string
    {
        return 'ansible';
    }

    public function apply(DeploymentOrder $order): DeploymentOutcome
    {
        return $this->run($order, check: false);
    }

    public function verify(DeploymentOrder $order): DeploymentOutcome
    {
        return $this->run($order, check: true);
    }

    /**
     * The argument list a run would use. Public so a test can see it without
     * anything being executed.
     *
     * @return list<string>
     */
    public function command(DeploymentOrder $order, bool $check, string $extraVarsFile): array
    {
        $this->refuseUnlessRunnable($order);

        $command = [
            'ansible-playbook',
            '-i', 'inventories/'.$order->environment->value,
            'playbooks/'.$order->playbook,
            '--limit', $order->host,
            '--extra-vars', '@'.$extraVarsFile,
        ];

        if ($check) {
            $command[] = '--check';
            $command[] = '--diff';
        }

        return $command;
    }

    private function run(DeploymentOrder $order, bool $check): DeploymentOutcome
    {
        $this->refuseUnlessRunnable($order);

        $vars = [];

        foreach ($order->configuration as $component => $configuration) {
            foreach ($configuration as $key => $value) {
                $vars[$component.'_'.$key] = $value;
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'lynomia-extra-vars-');

        if ($file === false) {
            return DeploymentOutcome::failed(FailureClass::Transient, 'Could not write the variables file.', []);
        }

        try {
            file_put_contents($file, json_encode((object) $vars, JSON_THROW_ON_ERROR));

            $command = $this->command($order, $check, $file);
            $result = ($this->runner ?? $this->process(...))($command, (string) $this->iacPath.'/ansible', $order->timeoutSeconds);
        } finally {
            @unlink($file);
        }

        $steps = [['name' => $check ? 'check' : 'playbook', 'outcome' => $result['timedOut'] ? 'timed_out' : ($result['exit'] === 0 ? 'passed' : 'failed')]];

        if ($result['timedOut']) {
            return DeploymentOutcome::indeterminate(
                sprintf('ansible-playbook did not finish within %d seconds and was stopped. The machine may be part-way through the change.', $order->timeoutSeconds),
                $steps,
            );
        }

        if ($result['exit'] !== 0) {
            // Exit 4 is Ansible's "unreachable"; anything else is the playbook
            // itself refusing or failing, which a retry will not change.
            return DeploymentOutcome::failed(
                $result['exit'] === 4 ? FailureClass::Transient : FailureClass::Permanent,
                sprintf('ansible-playbook exited %d.', $result['exit']),
                $steps,
            );
        }

        if (! $check) {
            return DeploymentOutcome::succeeded($steps);
        }

        // A clean check run against a machine that already matches its
        // profile reports no changes. That is the only presence this driver
        // will claim, and it claims it per component the order named.
        $facts = [];

        foreach (array_keys($order->verifications) as $component) {
            $facts['software.'.$component.'.present'] = 'true';
            $steps[] = ['name' => 'verify:'.$component, 'outcome' => 'passed', 'detail' => 'check mode reported no change'];
        }

        return DeploymentOutcome::succeeded($steps, $facts);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, output: string, timedOut: bool}
     */
    private function process(array $command, string $cwd, int $timeout): array
    {
        $process = new Process($command, $cwd, ['ANSIBLE_FORCE_COLOR' => '0', 'ANSIBLE_NOCOLOR' => '1'], null, (float) $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['exit' => -1, 'output' => $process->getOutput(), 'timedOut' => true];
        }

        return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput(), 'timedOut' => false];
    }

    private function refuseUnlessRunnable(DeploymentOrder $order): void
    {
        if ($this->ci) {
            throw DeploymentRefused::controllerRefused('CI validates infrastructure; a person applies it. The ansible controller refuses to run where CI is set.');
        }

        if ($this->iacPath === null || $this->iacPath === '' || ! is_dir($this->iacPath.'/ansible/playbooks')) {
            throw DeploymentRefused::controllerRefused('INFRASTRUCTURE_IAC_PATH does not point at a checked-out infrastructure tree on this host.');
        }

        if (preg_match(self::PLAYBOOK_SHAPE, $order->playbook) !== 1 || ! is_file($this->iacPath.'/ansible/playbooks/'.$order->playbook)) {
            throw DeploymentRefused::controllerRefused(sprintf('The %s playbook is not in the tree.', $order->playbook));
        }

        if (preg_match(self::HOST_SHAPE, $order->host) !== 1) {
            throw DeploymentRefused::controllerRefused('The host name is not a hostname.');
        }
    }
}
