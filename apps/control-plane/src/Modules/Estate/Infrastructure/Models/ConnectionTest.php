<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Estate\Domain\Enums\ConnectionState;

/**
 * What happened when we last tried to reach something, step by step.
 *
 * The steps are the useful part. "Auth failed" tells an operator to check a
 * credential; "TCP ok, TLS ok, auth ok, permissions insufficient" tells them
 * the credential is right and the role is wrong, which is a different afternoon.
 *
 * Nothing in `steps` is ever the thing that was sent. A step records that
 * authentication was attempted and rejected, never with what.
 *
 * @property ConnectionState $result
 * @property array<int, array{name: string, outcome: string, detail?: string}> $steps
 */
class ConnectionTest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => ConnectionState::class,
            'steps' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    /**
     * @return BelongsTo<ProviderInstance, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderInstance::class, 'provider_instance_id');
    }
}
