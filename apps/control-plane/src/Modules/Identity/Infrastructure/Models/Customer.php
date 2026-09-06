<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;

/**
 * The commercial counterparty: what owns services and receives invoices.
 *
 * @property string $id
 * @property CustomerType $type
 * @property CustomerStatus $status
 * @property string $display_name
 * @property string $currency
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'tax_exempt' => 'boolean',
            'requires_manual_review' => 'boolean',
            'suspended_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, CustomerMember>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_members')
            ->using(CustomerMember::class)
            ->withPivot(['id', 'role', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CustomerMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CustomerMember::class);
    }

    public function owner(): ?User
    {
        return $this->users()
            ->wherePivot('role', CustomerRole::Owner->value)
            ->first();
    }

    public function canPurchase(): bool
    {
        return $this->status->canPurchase();
    }
}
