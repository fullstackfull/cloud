<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ServerComponentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;

/**
 * One part inside one machine.
 *
 * Rows are written by discovery and are never deleted when a part stops being
 * reported. A drive that vanishes from the controller's inventory has either
 * been pulled or has died, and both are things somebody needs to see; deleting
 * the row would erase the serial number of the disk a customer's data was on
 * at exactly the moment that serial number became interesting.
 *
 * @property string $id
 * @property string $dedicated_server_id
 * @property ComponentKind $kind
 * @property ?string $model
 * @property ?string $serial
 * @property int $quantity
 * @property ?array<string, mixed> $attributes
 * @property ComponentHealth $health
 * @property ?CarbonImmutable $health_checked_at
 */
class ServerComponent extends Model
{
    /** @use HasFactory<ServerComponentFactory> */
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
        'health' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ComponentKind::class,
            'health' => ComponentHealth::class,
            'quantity' => 'integer',
            'attributes' => 'array',
            'health_checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DedicatedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DedicatedServer::class, 'dedicated_server_id');
    }

    /**
     * This part's MAC address, normalised, or null.
     *
     * Normalisation is not cosmetic: the provisioning VLAN's DHCP server is
     * told which MAC may boot, and an address stored in the vendor's spelling
     * rather than the platform's is a machine that silently never boots.
     */
    public function macAddress(): ?string
    {
        $raw = $this->attributeValue('mac_address');

        if ($raw === null) {
            return null;
        }

        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $raw));

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** Whether this NIC is the one wired to the provisioning VLAN. */
    public function isProvisioningInterface(): bool
    {
        if ($this->kind !== ComponentKind::Nic) {
            return false;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $this->getAttribute('attributes') ?? [];

        return ($attributes['pxe_enabled'] ?? false) === true;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfKind(Builder $query, ComponentKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('health', [ComponentHealth::Warning->value, ComponentHealth::Critical->value]);
    }

    /**
     * Read one key out of the jsonb attributes as a string.
     *
     * A helper rather than direct array access because `attributes` collides
     * with Eloquent's own property of that name: reading `$this->attributes`
     * inside the model returns the raw attribute bag, not the cast column.
     */
    private function attributeValue(string $key): ?string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = $this->getAttribute('attributes') ?? [];

        $value = $decoded[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
