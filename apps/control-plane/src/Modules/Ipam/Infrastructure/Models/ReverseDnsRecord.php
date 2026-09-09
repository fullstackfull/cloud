<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Database\Factories\ReverseDnsRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * The PTR record published for an address.
 *
 * @property string $id
 * @property string $ip_address_id
 * @property string $hostname
 * @property ReverseDnsStatus $status
 * @property ?string $last_error
 */
class ReverseDnsRecord extends Model
{
    /** @use HasFactory<ReverseDnsRecordFactory> */
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
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReverseDnsStatus::class,
        ];
    }

    /**
     * @return BelongsTo<IpAddress, $this>
     */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    /**
     * Record why the DNS provider refused this record.
     *
     * The message is redacted before it is stored. A DNS API client that fails
     * routinely quotes the request it sent, and that request carried the zone
     * API token; last_error is read by everyone with support access, so it is
     * exactly the wrong place for one to come to rest.
     */
    public function recordFailure(string $error): void
    {
        $this->forceFill([
            'status' => ReverseDnsStatus::Failed,
            'last_error' => app(SecretRedactor::class)->redactString($error),
        ])->save();
    }
}
