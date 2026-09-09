<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Services;

use Lynomia\Modules\Infrastructure\Domain\DTOs\ComponentDefinition;
use Lynomia\Modules\Infrastructure\Domain\DTOs\Plan;
use Lynomia\Modules\Infrastructure\Domain\DTOs\PlannedChange;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ProfileDefinition;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;

/**
 * Desired state minus current facts, priced by risk, with a fingerprint.
 *
 * A pure function: profile, overrides, the machine's current facts and its
 * safety classification in; a plan out. It contacts nothing. What it knows
 * about the machine is what discovery and previous verifications wrote as
 * facts under `software.<component>.present`, and a component with no such
 * fact is a component to install — the plan says "install" and the run's
 * idempotent role decides what that means on the day.
 *
 * The fingerprint is over what would be done (profile, ordered changes with
 * their configuration) and nothing else. Two plans that would do the same
 * thing share a fingerprint, so an approval survives a re-plan that changed
 * nothing; a re-plan that changed anything, however small, gets a new one and
 * the old approval no longer covers it.
 */
final readonly class PlanEngine
{
    public function __construct(
        private SoftwareCatalogue $catalogue,
    ) {}

    /**
     * @param  array<string, string>  $overrides  Validated overrides, `component.key` => value.
     * @param  array<string, string>  $facts  Current facts, key => value.
     * @param  list<string>  $licensedProducts  Licence products with a permitting licence in the machine's environment.
     */
    public function plan(
        ProfileDefinition $profile,
        array $overrides,
        array $facts,
        SafetyClass $classification,
        bool $allowReimage,
        array $licensedProducts,
    ): Plan {
        $changes = [];
        $unchanged = [];
        $blockers = [];
        $seen = [];

        foreach ($this->catalogue->componentsOf($profile) as $component) {
            foreach ($component->dependsOn as $dependency) {
                if (! isset($seen[$dependency])) {
                    $blockers[] = ['code' => 'dependency_order', 'detail' => sprintf('%s depends on %s, which the profile does not install before it.', $component->key, $dependency)];
                }
            }

            $seen[$component->key] = true;

            if ($component->requiresLicence && ! in_array((string) $component->licenceProduct, $licensedProducts, strict: true)) {
                $blockers[] = ['code' => 'licence', 'detail' => sprintf('%s needs a %s licence and none permits in this environment.', $component->key, $component->licenceProduct)];
            }

            $configuration = $this->configurationFor($component, $overrides);

            if (($facts['software.'.$component->key.'.present'] ?? null) === 'true') {
                $unchanged[] = ['component' => $component->key, 'reason' => 'Present at the last verification.'];

                continue;
            }

            $changes[] = new PlannedChange(
                component: $component->key,
                action: 'install',
                role: $component->ansibleRole,
                risk: $component->risk,
                requiresReboot: $component->requiresReboot,
                configuration: $configuration,
                reason: 'Not present at the last verification, or never verified.',
            );
        }

        $risk = PlanRisk::worst(...array_map(static fn (PlannedChange $c): PlanRisk => $c->risk, $changes));
        $required = $risk->requires();
        $destructive = $risk === PlanRisk::Destructive;
        $reboot = array_any($changes, static fn (PlannedChange $c): bool => $c->requiresReboot);

        // The classification is a planning input, so the blocker is on the
        // plan a person reads rather than on the run they never get to start.
        if ($changes !== [] && ! $classification->permits($destructive ? InfrastructureAction::Reimage : InfrastructureAction::Configure)) {
            $blockers[] = ['code' => 'safety_class', 'detail' => sprintf('This plan requires %s and the machine is classified %s.', $required->value, $classification->value)];
        }

        if ($destructive && ! $allowReimage) {
            $blockers[] = ['code' => 'reimage_clearance', 'detail' => 'This plan is destructive and the machine is not cleared for a wipe.'];
        }

        return new Plan(
            profile: $profile->key,
            playbook: $profile->playbook,
            changes: $changes,
            unchanged: $unchanged,
            blockers: $blockers,
            risk: $risk,
            requiredSafetyClass: $required,
            requiresReboot: $reboot,
            isDestructive: $destructive,
            fingerprint: $this->fingerprint($profile, $changes),
        );
    }

    /**
     * @param  list<PlannedChange>  $changes
     */
    public function fingerprint(ProfileDefinition $profile, array $changes): string
    {
        $canonical = [
            'profile' => $profile->key,
            'playbook' => $profile->playbook,
            'changes' => array_map(static fn (PlannedChange $c): array => [
                'component' => $c->component,
                'action' => $c->action,
                'role' => $c->role,
                'configuration' => $c->configuration,
            ], $changes),
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function configurationFor(ComponentDefinition $component, array $overrides): array
    {
        $configuration = [];

        foreach ($component->accepts as $key) {
            $full = $component->key.'.'.$key;

            if (array_key_exists($full, $overrides)) {
                $configuration[$key] = $overrides[$full];
            }
        }

        ksort($configuration);

        return $configuration;
    }
}
