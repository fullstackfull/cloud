<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Models;

use Database\Factories\ProviderInstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * One account with one external or infrastructure provider.
 *
 * An instance, not a setting, because the platform genuinely needs two of most
 * of these at once: a registrar sandbox beside a registrar production account,
 * a Stripe test key beside a live one, a staging cluster beside the real one.
 * Expressing that as a single environment variable is what makes it possible
 * to charge a real card from a test run.
 *
 * The vendor lives in `driver` and nowhere else. Nothing in any module's domain
 * layer asks which vendor this is; it asks the category whether a capability is
 * supported, and gets an answer that came from the provider itself.
 *
 * @property string $id
 * @property string $name
 * @property ProviderCategory $category
 * @property string $driver
 * @property DeploymentEnvironment $environment
 * @property ProviderState $state
 * @property ConnectionState $connection_state
 * @property ReadinessState $readiness
 * @property ?BlockerReason $blocker
 */
class ProviderInstance extends Model
{
    /** @use HasFactory<ProviderInstanceFactory> */
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
        'connection_state' => 'not_tested',
        'state' => 'draft',
        'readiness' => 'not_ready',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProviderCategory::class,
            'environment' => DeploymentEnvironment::class,
            'state' => ProviderState::class,
            'connection_state' => ConnectionState::class,
            'readiness' => ReadinessState::class,
            'blocker' => BlockerReason::class,
            'last_connection_test_at' => 'immutable_datetime',
            'last_discovery_at' => 'immutable_datetime',
            'last_successful_operation_at' => 'immutable_datetime',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CredentialReference, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(CredentialReference::class, 'credential_reference_id');
    }

    /**
     * @return BelongsTo<Licence, $this>
     */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class);
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    /**
     * @return HasMany<ProviderCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(ProviderCapability::class);
    }

    /**
     * @return HasMany<ConnectionTest, $this>
     */
    public function connectionTests(): HasMany
    {
        return $this->hasMany(ConnectionTest::class);
    }

    /**
     * Is this instance actually serving work?
     *
     * Enabled and connected, both. An instance somebody enabled last week whose
     * credential was revoked yesterday is not serving anything, and a screen
     * that says otherwise sends an operator looking in the wrong place.
     */
    public function isServing(): bool
    {
        return $this->state->serving() && $this->connection_state->usable();
    }
}
