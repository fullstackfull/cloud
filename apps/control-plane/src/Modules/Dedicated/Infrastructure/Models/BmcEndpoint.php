<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BmcEndpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;

/**
 * How to reach one machine's out-of-band controller.
 *
 * Note what this row does NOT have: a password column. It holds a username and
 * a `credentials_reference`, which is a key into configuration; the secret
 * itself is read at connection time and never written here. A BMC password in
 * the database is a BMC password in every backup, every read replica and every
 * support export — and access to an iLO or IPMI interface is complete access
 * to the physical host and every tenant sharing it.
 *
 * `verify_tls` lives on the row rather than in configuration for the same
 * reason it does on a compute cluster: a machine with a self-signed
 * certificate turns verification off for itself alone, and cannot turn it off
 * for the fleet.
 *
 * @property string $id
 * @property string $dedicated_server_id
 * @property BmcProtocol $protocol
 * @property string $address
 * @property ?int $port
 * @property ?string $username
 * @property ?string $credentials_reference
 * @property bool $verify_tls
 * @property ?string $firmware_version
 * @property ?CarbonImmutable $last_contacted_at
 * @property ?string $last_error
 */
class BmcEndpoint extends Model
{
    /** @use HasFactory<BmcEndpointFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => BmcProtocol::class,
            'port' => 'integer',
            'verify_tls' => 'boolean',
            'last_contacted_at' => 'immutable_datetime',
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
     * The port to connect on: the row's, or the protocol's standard one.
     *
     * Resolved here rather than defaulted in the column so that a protocol's
     * standard port is a property of the protocol, and a row that says nothing
     * keeps saying nothing rather than freezing today's default into data.
     */
    public function effectivePort(): int
    {
        return $this->port ?? $this->protocol->defaultPort();
    }

    /**
     * The configuration key the credentials for this endpoint are read from.
     *
     * Falls back to the address so that a fleet can be configured by host
     * without every row naming a reference — but the value is still only ever
     * a key, never a secret.
     */
    public function credentialsReference(): string
    {
        $reference = $this->credentials_reference;

        return is_string($reference) && trim($reference) !== '' ? $reference : $this->address;
    }

    /**
     * The base URL for an HTTP-speaking controller.
     *
     * Always https. There is no plaintext option and no configuration switch
     * to add one: the credential this URL carries is a credential for the
     * physical machine.
     */
    public function baseUrl(): string
    {
        return sprintf('https://%s:%d', $this->address, $this->effectivePort());
    }
}
