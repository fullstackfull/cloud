<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DnsRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\IllegalDnsTransitionException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;

/**
 * One record the platform has asked a provider to publish.
 *
 * The row is the platform's *intent and last observation*, not the zone. What
 * the zone actually serves is the provider's business; the two are compared by
 * reconciliation, and the fact that they can differ is why this table exists
 * separately at all.
 *
 * @property string $id
 * @property string $dns_zone_id
 * @property DnsRecordType $type
 * @property string $name
 * @property string $content
 * @property int $ttl
 * @property ?int $priority
 * @property ?array<string, mixed> $data
 * @property DnsState $state
 * @property ?string $provider_record_id
 * @property ?string $failure_reason
 * @property ?CarbonImmutable $last_published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DnsRecord extends Model
{
    /** @use HasFactory<DnsRecordFactory> */
    use HasFactory, HasUlids;

    protected $table = 'dns_records';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DnsRecordType::class,
            'state' => DnsState::class,
            'data' => 'array',
            'ttl' => 'integer',
            'priority' => 'integer',
            'last_published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DnsZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    /**
     * This row in the shape the provider contract speaks.
     *
     * Built here rather than in each job, so that the rules on
     * {@see DnsRecordValue} are applied to every row on its way out — including
     * a row an operator re-published a month after it was written.
     */
    public function toValue(): DnsRecordValue
    {
        return DnsRecordValue::of(
            type: $this->type,
            name: $this->name,
            content: $this->content,
            ttl: $this->ttl,
            priority: $this->priority,
            data: $this->data ?? [],
            id: $this->provider_record_id,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws IllegalDnsTransitionException
     */
    public function transitionTo(DnsState $next, array $attributes = []): void
    {
        if (! $this->state->canBecome($next)) {
            throw IllegalDnsTransitionException::between((string) $this->getKey(), $this->state, $next);
        }

        $this->forceFill([...$attributes, 'state' => $next])->save();
    }
}
