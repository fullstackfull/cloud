<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ServerRegistration;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;

/**
 * Write down that a machine exists.
 *
 * The first step of onboarding and deliberately the least powerful one: a
 * machine arrives classified do_not_touch and nothing about registering it can
 * change that. An operator who wants to look at it has to say so, separately,
 * and that decision is recorded against their name.
 *
 * The registration itself carries no credential and does not connect to
 * anything. A row here is a claim that a machine exists, which is exactly what
 * arrives with a delivery note before anybody has racked it.
 */
final readonly class RegisterServer
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(ServerRegistration $registration): ManagedServer
    {
        return $this->record->execute(
            act: fn (): ManagedServer => ManagedServer::create([
                'name' => $registration->name,
                'environment' => $registration->environment,
                'state' => ServerState::Registered,

                // Not a parameter. There is no argument to this action that can
                // produce a touchable machine, so no caller — an import, a
                // seeder, a future wizard step somebody adds in a hurry — can
                // register one already cleared for work.
                'safety_class' => SafetyClass::DoNotTouch,
                'allow_reimage' => false,

                // Set here rather than left to the column default. A default
                // applies in the database and not on the model create() hands
                // back, so a caller that renders the result immediately reads
                // null — which is how a screen ends up with a blank state and
                // a 500 rather than "not tested". The same defect cost this
                // platform a WordPress order in the last phase.
                'connection_state' => ConnectionState::NotTested,

                'datacenter_id' => $registration->datacenterId,
                'rack_id' => $registration->rackId,
                'rack_unit' => $registration->rackUnit,
                'height_units' => $registration->heightUnits ?? 1,

                'vendor' => $registration->vendor,
                'model' => $registration->model,
                'serial' => $registration->serial,
                'asset_tag' => $registration->assetTag,

                'management_address' => $registration->managementAddress,
                'management_port' => $registration->managementPort,
                'bmc_address' => $registration->bmcAddress,
                'bmc_port' => $registration->bmcPort,

                'operating_system' => $registration->operatingSystem,
                'notes' => $registration->notes,
            ]),
            describe: fn (ManagedServer $server): AuditedAct => new AuditedAct(
                action: AuditAction::ServerRegistered,
                subject: $server,
                context: [
                    'name' => $server->name,
                    'environment' => $server->environment->value,
                    // Recorded because the interesting question later is
                    // whether anything ever raised it, and from what.
                    'safety_class' => $server->safety_class->value,
                ],
            ),
        );
    }
}
