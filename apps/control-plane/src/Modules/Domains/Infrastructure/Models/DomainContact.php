<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;

/**
 * Who a registry was told owns, administers, operates or pays for a name.
 *
 * ---------------------------------------------------------------------------
 * Encrypted at rest, and every column of it
 * ---------------------------------------------------------------------------
 *
 * This is the only table in the platform that holds a home address. A
 * registrant contact is a real person's name, postal address, telephone number
 * and email, submitted to a registry because a registry demands it — and in
 * many cases published in a public WHOIS by that registry afterwards. That the
 * registry may publish it is not a reason for this platform to hold it
 * loosely: a database dump from here would be a list of customers' home
 * addresses joined to what they own.
 *
 * So every personal column is `encrypted`, none of them appears in a metric
 * label, none is written to a log line, and the audit trail records that a
 * contact changed without recording to what.
 *
 * A snapshot, not a pointer. See the migration for why a registration made
 * last year must keep last year's registrant.
 *
 * @property string $id
 * @property string $domain_id
 * @property DomainContactRole $role
 * @property string $name
 * @property ?string $organisation
 * @property string $email
 * @property string $phone
 * @property string $address_line_one
 * @property ?string $address_line_two
 * @property string $city
 * @property ?string $region
 * @property ?string $postal_code
 * @property string $country
 * @property ?string $provider_reference
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DomainContact extends Model
{
    /** @use HasFactory<DomainContactFactory> */
    use HasFactory, HasUlids;

    protected $table = 'domain_contacts';

    protected $guarded = [];

    /**
     * Never serialised by accident.
     *
     * `toArray()` on this model is one careless debug statement away from a
     * support ticket containing a customer's home address. The resource layer
     * names the fields it means to show, to the person entitled to see them.
     *
     * @var list<string>
     */
    protected $hidden = [
        'name', 'organisation', 'email', 'phone',
        'address_line_one', 'address_line_two', 'city', 'region', 'postal_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => DomainContactRole::class,
            'name' => 'encrypted',
            'organisation' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'address_line_one' => 'encrypted',
            'address_line_two' => 'encrypted',
            'city' => 'encrypted',
            'region' => 'encrypted',
            'postal_code' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
