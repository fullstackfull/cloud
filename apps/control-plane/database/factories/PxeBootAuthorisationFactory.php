<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;

/**
 * @extends Factory<PxeBootAuthorisation>
 */
class PxeBootAuthorisationFactory extends Factory
{
    protected $model = PxeBootAuthorisation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dedicated_server_id' => DedicatedServer::factory(),
            'os_install_profile_id' => OsInstallProfile::factory(),
            'provisioning_job_id' => null,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => PxeAuthorisationStatus::Pending,
            'expires_at' => now()->addHour(),
            // Not nullable in the schema and not optional in the domain: a
            // reinstall with no recorded reason is the row an investigation
            // cannot use.
            'authorisation_reason' => 'Initial provisioning',
        ];
    }

    public function forServer(DedicatedServer $server): static
    {
        return $this->state(fn (): array => ['dedicated_server_id' => $server->getKey()]);
    }

    /**
     * An authorisation whose window has already closed, for testing that a
     * lapsed permission cannot be used.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function status(PxeAuthorisationStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
