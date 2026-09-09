<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * @extends Factory<ManagedServer>
 */
class ManagedServerFactory extends Factory
{
    protected $model = ManagedServer::class;

    /**
     * A machine as it arrives: untouchable, and nothing known about it.
     *
     * The default matters. A factory that produced a configuration_allowed
     * machine would let every test that forgot to think about safety pass, and
     * the tests that matter here are the ones that do think about it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'pve-'.Str::lower(Str::random(6)),
            'environment' => DeploymentEnvironment::Staging,
            'state' => ServerState::Registered,
            'safety_class' => SafetyClass::DoNotTouch,
            'allow_reimage' => false,
            'connection_state' => ConnectionState::NotTested,
            'management_address' => 'fake://connected',
        ];
    }

    public function classified(SafetyClass $class): self
    {
        return $this->state(fn (): array => ['safety_class' => $class]);
    }

    /** Cleared for one destructive piece of work, as the database requires. */
    public function clearedForReimage(): self
    {
        return $this->state(fn (): array => [
            'safety_class' => SafetyClass::ReimageAllowed,
            'allow_reimage' => true,
        ]);
    }

    /**
     * Point the machine at a fake endpoint that fails in a chosen way.
     *
     * The marker travels through the tester, the connection state, the blocker
     * and onto the screen, so a test written this way exercises the whole chain
     * rather than asserting on an enum.
     */
    public function reachableAs(string $marker): self
    {
        return $this->state(fn (): array => ['management_address' => 'fake://'.$marker]);
    }

    /**
     * Bind a controlled BMC to the machine, so it can be reached.
     *
     * Every test of the machine path needs one: the driver a test speaks is
     * the one bound to the machine, and a machine with nothing bound is
     * refused before any adapter is chosen.
     */
    public function withBmc(): self
    {
        return $this->afterCreating(function (ManagedServer $server): void {
            ProviderInstance::factory()->create([
                'name' => 'bmc-'.$server->name,
                'category' => ProviderCategory::Bmc,
                'driver' => 'fake_bmc',
                'environment' => $server->environment,
                'endpoint' => $server->bmc_address ?? $server->management_address,
                'managed_server_id' => $server->getKey(),
            ]);
        });
    }

    public function inProduction(): self
    {
        return $this->state(fn (): array => ['environment' => DeploymentEnvironment::Production]);
    }
}
