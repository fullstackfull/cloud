<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Estate\Domain\Enums\CredentialState;
use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;
use Lynomia\Modules\Estate\Infrastructure\Models\CredentialReference;

/**
 * @extends Factory<CredentialReference>
 */
class CredentialReferenceFactory extends Factory
{
    protected $model = CredentialReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'credential-'.Str::lower(Str::random(6)),
            'purpose' => 'connection test',
            'environment' => EstateEnvironment::Staging,
            'backend' => 'controller_environment',
            'backend_reference' => 'LYNOMIA_TEST_'.Str::upper(Str::random(6)),
            'state' => CredentialState::Configured,
        ];
    }

    public function forEnvironment(EstateEnvironment $environment): self
    {
        return $this->state(fn (): array => ['environment' => $environment]);
    }

    public function missing(): self
    {
        return $this->state(fn (): array => ['state' => CredentialState::Missing]);
    }
}
