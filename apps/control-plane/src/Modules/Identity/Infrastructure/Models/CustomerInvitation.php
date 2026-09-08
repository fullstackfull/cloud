<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\InvitationStatus;

/**
 * An offer of membership, made to an email address.
 *
 * The token never appears on this model. It is generated once, hashed into
 * `token_hash`, and handed to the mail; from then on the only way to reach a
 * row from a token is {@see forToken()}, which hashes and looks up. There is
 * deliberately no accessor that could return one, because a resource class
 * that serialised an invitation would then be one property away from mailing
 * every pending token to whoever asked for the list.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $email
 * @property CustomerRole $role
 * @property string $token_hash
 * @property ?string $invited_by_user_id
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $accepted_at
 * @property ?CarbonImmutable $declined_at
 * @property ?CarbonImmutable $revoked_at
 * @property int $sent_count
 * @property ?CarbonImmutable $last_sent_at
 * @property ?string $accepted_member_id
 * @property CarbonImmutable $created_at
 */
class CustomerInvitation extends Model
{
    /** @use HasFactory<CustomerInvitationFactory> */
    use HasFactory;

    use HasUlids;

    public $incrementing = false;

    protected $table = 'customer_invitations';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CustomerRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'sent_count' => 'integer',
        ];
    }

    /**
     * The hash a token would have. One place, so the write side and the read
     * side cannot disagree about the algorithm.
     */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return Builder<self>
     */
    public static function forToken(string $token): Builder
    {
        return self::query()->where('token_hash', self::hashOf($token));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', CarbonImmutable::now());
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->declined_at !== null => InvitationStatus::Declined,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function isOpen(): bool
    {
        return $this->status()->isOpen();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
