<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;

/**
 * Membership of a user in a customer account.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $user_id
 * @property CustomerRole $role
 */
class CustomerMember extends Pivot
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'customer_members';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CustomerRole::class,
            'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who invited this person. Named `inviter` rather than `invitedBy` because
     * the column is `invited_by` and a relation with the column's name would
     * shadow the attribute.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
