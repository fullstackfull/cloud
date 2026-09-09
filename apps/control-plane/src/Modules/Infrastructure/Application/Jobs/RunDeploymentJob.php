<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Infrastructure\Application\Actions\DetectInfrastructureDrift;
use Lynomia\Modules\Infrastructure\Domain\Contracts\DeploymentController;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOrder;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOutcome;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\FactSource;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * One run of the chain's last links: claim, re-check, apply, verify, record.
 *
 * Re-check, because the queue is not the request. Between the row being
 * written and the worker picking it up the plan may have been re-planned,
 * the approval revoked, the machine reclassified. Every one of those is
 * checked again here against the locked rows, and a mismatch ends the job
 * as failed with the reason — nothing is started.
 *
 * One attempt. A run that did not finish is INDETERMINATE and stays so until
 * a person has looked at the machine; the Timeout Rule forbids retrying a
 * playbook that may be half-way through /etc.
 */
final class RunDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $deploymentJobId,
    ) {}

    public function handle(
        DeploymentController $controller,
        SafetyGate $gate,
        SoftwareCatalogue $catalogue,
        RecordActAtomically $record,
        SecretRedactor $redactor,
        DetectInfrastructureDrift $drift,
    ): void {
        $job = $this->claim();

        if ($job === null) {
            return;
        }

        $server = $job->server;

        try {
            $order = $this->order($job, $server, $gate, $catalogue);
        } catch (DeploymentRefused $refused) {
            $this->finish($job, $server, DeploymentState::Failed, FailureClass::Permanent, $refused->getMessage(), [['name' => 'preflight', 'outcome' => 'failed', 'detail' => $refused->code_]], [], $record);

            return;
        }

        try {
            $outcome = $job->kind === DeploymentKind::Apply->value ? $controller->apply($order) : DeploymentOutcome::succeeded([]);
        } catch (Throwable $e) {
            $outcome = DeploymentOutcome::failed(FailureClass::Permanent, $redactor->redactString($e->getMessage()), [['name' => 'controller', 'outcome' => 'failed']]);
        }

        $steps = $outcome->steps;

        if ($outcome->indeterminate) {
            $this->finish($job, $server, DeploymentState::Indeterminate, FailureClass::Timeout, $outcome->detail, $steps, [], $record);

            return;
        }

        if (! $outcome->succeeded) {
            $this->finish($job, $server, DeploymentState::Failed, $outcome->failureClass, $outcome->detail, $steps, [], $record);

            return;
        }

        $job->forceFill(['state' => DeploymentState::Verifying, 'steps' => $steps])->save();

        try {
            $verified = $controller->verify($order);
        } catch (Throwable $e) {
            $verified = DeploymentOutcome::failed(FailureClass::Permanent, $redactor->redactString($e->getMessage()), [['name' => 'verify', 'outcome' => 'failed']]);
        }

        $steps = [...$steps, ...$verified->steps];

        if ($verified->indeterminate) {
            $this->finish($job, $server, DeploymentState::Indeterminate, FailureClass::Timeout, $verified->detail, $steps, [], $record);

            return;
        }

        if (! $verified->succeeded) {
            // The change may have been made and cannot be confirmed. Not
            // failed — nothing says the machine is wrong — and not completed.
            $this->finish($job, $server, DeploymentState::NeedsReview, $verified->failureClass, $verified->detail, $steps, [], $record);

            return;
        }

        $this->finish($job, $server, DeploymentState::Completed, null, null, $steps, $verified->facts, $record);

        // A verify that came back clean writes fresh facts; a component the
        // profile wants and the facts do not show is drift, and the drift
        // queue is where a person will see it.
        $drift->forServer($server->fresh());
    }

    private function claim(): ?DeploymentJob
    {
        return DB::transaction(function (): ?DeploymentJob {
            $job = DeploymentJob::query()->lockForUpdate()->find($this->deploymentJobId);

            if ($job === null || ! $job->state->mayBeStarted()) {
                return null;
            }

            $job->forceFill(['state' => DeploymentState::Applying, 'started_at' => CarbonImmutable::now()])->save();

            return $job;
        });
    }

    private function order(DeploymentJob $job, ManagedServer $server, SafetyGate $gate, SoftwareCatalogue $catalogue): DeploymentOrder
    {
        $kind = DeploymentKind::from($job->kind);

        if ($kind === DeploymentKind::Apply) {
            $plan = $job->plan ?? throw DeploymentRefused::noPlan($server->name);
            $approval = $job->approval ?? throw DeploymentRefused::notApproved($server->name, $plan->fingerprint);

            // The replay guard. An approval is for one fingerprint; if the
            // plan was recomputed to something else, or the approval was
            // revoked, this is not the run that was approved.
            if ($approval->revoked_at !== null || $approval->approved_fingerprint !== $plan->fingerprint) {
                throw DeploymentRefused::fingerprintMismatch();
            }

            $current = $server->plans()->latest('created_at')->first();

            if ($current === null || $current->getKey() !== $plan->getKey()) {
                throw DeploymentRefused::planSuperseded();
            }

            $gate->assert($server->name, $server->safety_class, $server->allow_reimage, $plan->is_destructive ? InfrastructureAction::Reimage : InfrastructureAction::Configure);
        } else {
            $gate->assert($server->name, $server->safety_class, $server->allow_reimage, InfrastructureAction::Read);
        }

        $desired = $server->desiredState()->with('profile')->first() ?? throw DeploymentRefused::noDesiredState($server->name);
        $profile = $catalogue->profile((string) $desired->profile?->key) ?? throw DeploymentRefused::unknownProfile((string) $desired->profile?->key);

        $configuration = [];
        $verifications = [];

        foreach ($catalogue->componentsOf($profile) as $component) {
            $verifications[$component->key] = $component->verification;
            $configuration[$component->key] = [];

            foreach ($component->accepts as $key) {
                $full = $component->key.'.'.$key;
                if (isset($desired->overrides[$full])) {
                    $configuration[$component->key][$key] = (string) $desired->overrides[$full];
                }
            }
        }

        return new DeploymentOrder(
            jobId: (string) $job->getKey(),
            kind: $kind,
            host: $server->name,
            managementAddress: (string) $server->management_address,
            environment: $server->environment,
            playbook: $profile->playbook,
            roles: array_map(static fn ($c): string => $c->ansibleRole, $catalogue->componentsOf($profile)),
            configuration: $configuration,
            verifications: $verifications,
            timeoutSeconds: (int) config($kind === DeploymentKind::Apply ? 'infrastructure.controller.apply_timeout_seconds' : 'infrastructure.controller.verify_timeout_seconds', 1800),
        );
    }

    /**
     * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
     * @param  array<string, string>  $facts
     */
    private function finish(
        DeploymentJob $job,
        ManagedServer $server,
        DeploymentState $state,
        ?FailureClass $class,
        ?string $detail,
        array $steps,
        array $facts,
        RecordActAtomically $record,
    ): void {
        $record->execute(
            act: function () use ($job, $server, $state, $class, $detail, $steps, $facts): DeploymentJob {
                $now = CarbonImmutable::now();

                $job->forceFill([
                    'state' => $state,
                    'failure_class' => $class?->value,
                    'failure_detail' => $detail,
                    'steps' => $steps,
                    'finished_at' => $state->isTerminal() || $state->waitsForSomebody() ? $now : null,
                ])->save();

                if ($state === DeploymentState::Completed) {
                    $this->writeFacts($server, $facts, $now);

                    $server->forceFill([
                        'last_verification_at' => $now,
                        'last_deployment_at' => $job->kind === DeploymentKind::Apply->value ? $now : $server->last_deployment_at,
                        'state' => $job->kind === DeploymentKind::Apply->value ? ServerState::Managed : $server->state,
                    ])->save();
                }

                return $job;
            },
            describe: fn (DeploymentJob $finished): AuditedAct => new AuditedAct(
                action: AuditAction::DeploymentFinished,
                subject: $finished,
                context: [
                    'server' => $server->name,
                    'kind' => $finished->kind,
                    'state' => $state->value,
                    'failure_class' => $class?->value,
                    'detail' => $detail,
                    'facts' => count($facts),
                ],
            ),
        );
    }

    /**
     * @param  array<string, string>  $facts
     */
    private function writeFacts(ManagedServer $server, array $facts, CarbonImmutable $now): void
    {
        $current = $server->facts()->current()->whereIn('key', array_keys($facts))->get()->keyBy('key');

        foreach ($facts as $key => $value) {
            $existing = $current->get($key);

            if ($existing !== null && $existing->value === $value) {
                continue;
            }

            $existing?->forceFill(['superseded_at' => $now])->save();

            ServerFact::create([
                'managed_server_id' => $server->getKey(),
                'key' => $key,
                'value' => $value,
                'source' => FactSource::Derived,
                'observed_at' => $now,
            ]);
        }
    }
}
