<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CredentialReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * A pointer to a secret, and everything about it except the secret.
 *
 * This table has never held a password and cannot leak one — which is a
 * stronger property than a table that holds passwords and remembers to redact
 * them. What it holds is the operational half: which backend has the value,
 * under what reference, when it was last tested, whether it worked, and when
 * it is due for rotation.
 *
 * `masked_hint` is deliberately narrow. It is at most the last four characters
 * of a PUBLIC identifier — a token id, an account number — so an operator can
 * tell two credentials apart on a screen. It is never derived from the secret
 * half, because four characters of a secret is four characters of a secret.
 *
 * @property string $id
 * @property string $name
 * @property string $purpose
 * @property string $backend
 * @property string $backend_reference
 * @property ?string $masked_hint
 * @property ?string $notes
 * @property ?string $revoked_reason
 * @property ?CarbonImmutable $last_tested_at
 * @property ?CarbonImmutable $rotates_at
 * @property ?CarbonImmutable $rotated_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?CarbonImmutable $created_at
 * @property-read ?int $provider_instances_count
 * @property-read ?int $servers_count
 * @property DeploymentEnvironment $environment
 * @property CredentialState $state
 * @property string $backend
 * @property string $backend_reference
 */
class CredentialReference extends Model
{
    /** @use HasFactory<CredentialReferenceFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * The state a row has before anything happens to it.
     *
     * This duplicates the column default on purpose. A default declared only
     * in the database applies during the INSERT and not to the model object
     * that create() hands back, so a caller that renders its own result reads
     * null for a column the table will happily report a value for one query
     * later. Declared here, every creation path starts in the same state,
     * including the ones written after this comment.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'state' => 'missing',
    ];

    /**
     * Hidden from every array and JSON rendering of this model.
     *
     * Neither of these is a secret — the backend reference is a path, not a
     * value — but a path into the secret store is a map for somebody who has
     * got as far as the API and should not be handed one. The admin surface
     * shows the name, the state and the hint.
     *
     * @var list<string>
     */
    protected $hidden = ['backend_reference'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => DeploymentEnvironment::class,
            'state' => CredentialState::class,
            'last_tested_at' => 'immutable_datetime',
            'rotates_at' => 'immutable_datetime',
            'rotation_reminder_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ProviderInstance, $this>
     */
    public function providerInstances(): HasMany
    {
        return $this->hasMany(ProviderInstance::class, 'credential_reference_id');
    }

    /**
     * May this credential be used for real work in that environment?
     *
     * The answer is never "yes, close enough". A staging token that happens to
     * work against production is the failure this method exists to prevent.
     *
     * Requires a credential somebody has proven, which is why it is not the
     * rule a connection test uses — see mayBeTried.
     */
    /**
     * @return HasMany<ManagedServer, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(ManagedServer::class, 'credential_reference_id');
    }

    /**
     * Withdrawn: nothing may try it, nothing may serve with it, and the row
     * stays so that everything pointing at it can say why it is blocked.
     */
    public function isRevoked(): bool
    {
        return $this->state === CredentialState::Revoked;
    }

    public function mayServe(DeploymentEnvironment $environment): bool
    {
        return $this->environment->satisfies($environment) && $this->state->usable();
    }

    /**
     * May this credential be used to find out whether it works?
     *
     * A separate question from mayServe, and the difference is not pedantry: a
     * credential reaches Valid only by being tested, so a test that demanded a
     * Valid credential could never run on a newly configured one. Every
     * credential would sit at Configured for ever and the control centre would
     * report an estate it had never contacted.
     *
     * The environment rule is identical — a staging token is never tried
     * against production, not even to see what happens. What differs is the
     * state: anything but a credential already known to be dead is worth
     * trying, because "is it dead" is exactly the question being asked.
     */
    public function mayBeTried(DeploymentEnvironment $environment): bool
    {
        if (! $this->environment->satisfies($environment)) {
            return false;
        }

        return match ($this->state) {
            CredentialState::Missing, CredentialState::Revoked => false,
            default => true,
        };
    }
}
