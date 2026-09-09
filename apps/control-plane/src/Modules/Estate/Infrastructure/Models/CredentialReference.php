<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Database\Factories\CredentialReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Estate\Domain\Enums\CredentialState;
use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;

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
 * @property string $name
 * @property EstateEnvironment $environment
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
            'environment' => EstateEnvironment::class,
            'state' => CredentialState::class,
            'last_tested_at' => 'immutable_datetime',
            'rotates_at' => 'immutable_datetime',
            'rotation_reminder_at' => 'immutable_datetime',
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
     * May this credential be used for work in that environment?
     *
     * The answer is never "yes, close enough". A staging token that happens to
     * work against production is the failure this method exists to prevent.
     */
    public function mayServe(EstateEnvironment $environment): bool
    {
        return $this->environment->satisfies($environment) && $this->state->usable();
    }
}
