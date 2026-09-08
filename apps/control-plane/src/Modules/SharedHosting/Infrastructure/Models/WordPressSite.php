<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WordPressSiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;

/**
 * A WordPress site: an account, a name, a certificate and an installation.
 *
 * The three booleans are separate from the state on purpose. The state is what
 * the customer is told; the booleans are what the platform has established.
 * Deriving one from the other in either direction loses information — a site
 * can be installed and not resolving, or resolving and not installed, and both
 * of those are ordinary Tuesday afternoons rather than corruption.
 *
 * @property string $id
 * @property string $customer_id
 * @property ?string $hosting_account_id
 * @property ?string $service_id
 * @property ?string $order_id
 * @property string $domain
 * @property ?string $domain_id
 * @property WordPressDomainSource $domain_source
 * @property WordPressSiteState $state
 * @property bool $dns_ready
 * @property bool $installed
 * @property SslStatus $ssl_status
 * @property ?CarbonImmutable $verified_at
 * @property ?string $site_url
 * @property ?string $admin_username
 * @property ?string $admin_email
 * @property ?string $wordpress_version
 * @property ?string $locale
 * @property ?string $failure_reason
 * @property ?string $review_reason
 * @property ?CarbonImmutable $reconciled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WordPressSite extends Model
{
    /** @use HasFactory<WordPressSiteFactory> */
    use HasFactory, HasUlids;

    protected $table = 'wordpress_sites';

    protected $guarded = [];

    /**
     * The administrator's address is personal data and is hidden from every
     * payload by default, whatever a resource forgets.
     *
     * @var list<string>
     */
    protected $hidden = ['admin_email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain_source' => WordPressDomainSource::class,
            'state' => WordPressSiteState::class,
            'ssl_status' => SslStatus::class,
            'dns_ready' => 'boolean',
            'installed' => 'boolean',

            // Encrypted at rest, like every other contact address this
            // platform files with somebody else.
            'admin_email' => 'encrypted',

            'verified_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<HostingAccount, $this>
     */
    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class, 'hosting_account_id');
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domainRecord(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    /**
     * Where the customer signs in, which is not the same as where the site is.
     *
     * Derived rather than stored: it is the site URL plus a fixed path that
     * WordPress itself defines, and a stored copy would be a second thing to
     * keep in step for no gain.
     */
    public function adminUrl(): ?string
    {
        return $this->site_url === null ? null : rtrim($this->site_url, '/').'/wp-admin/';
    }

    /**
     * Whether everything the platform promised is actually true.
     *
     * All four, and `verified_at` is the one that matters: an installer that
     * returned success is not a site that answers, and this platform has to
     * have looked.
     */
    public function isFullyLive(): bool
    {
        return $this->dns_ready
            && $this->installed
            && $this->ssl_status->isSecure()
            && $this->verified_at !== null;
    }
}
