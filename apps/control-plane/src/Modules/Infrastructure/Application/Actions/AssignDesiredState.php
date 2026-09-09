<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Services\OverrideRules;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DesiredState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\SoftwareProfile;

/**
 * What a machine is meant to be, stated.
 *
 * Assigning a profile changes nothing on the machine. It records an
 * intention that the plan engine reads, and the overrides are validated here
 * — against the profile's components' declared keys and the value shape —
 * before they are stored, so a stored override is always one a playbook may
 * be given.
 */
final readonly class AssignDesiredState
{
    public function __construct(
        private SoftwareCatalogue $catalogue,
        private OverrideRules $rules,
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function execute(ManagedServer $server, string $profileKey, array $overrides, User $operator): DesiredState
    {
        $definition = $this->catalogue->profile($profileKey) ?? throw DeploymentRefused::unknownProfile($profileKey);

        $profile = SoftwareProfile::query()->where('key', $profileKey)->first()
            ?? throw DeploymentRefused::unknownProfile($profileKey);

        if (! $profile->is_active) {
            throw DeploymentRefused::inactiveProfile($profileKey);
        }

        $accepted = $this->rules->validate($definition, $overrides);

        return $this->record->execute(
            act: function () use ($server, $profile, $accepted, $operator): DesiredState {
                $state = DesiredState::query()->updateOrCreate(
                    ['managed_server_id' => $server->getKey()],
                    [
                        'software_profile_id' => $profile->getKey(),
                        'overrides' => $accepted,
                        'assigned_by' => $operator->getKey(),
                    ],
                );

                if (in_array($server->state, [ServerState::Registered, ServerState::Connected, ServerState::Discovered], strict: true)) {
                    $server->forceFill(['state' => ServerState::Profiled])->save();
                }

                return $state->load('profile');
            },
            describe: fn (DesiredState $state): AuditedAct => new AuditedAct(
                action: AuditAction::DesiredStateAssigned,
                subject: $server,
                context: [
                    'server' => $server->name,
                    'profile' => $profile->key,
                    'overrides' => array_keys($accepted),
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
