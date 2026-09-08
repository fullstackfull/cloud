<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;

/**
 * One conversation between an account and the support team.
 *
 * @property string $id
 * @property string $customer_id
 * @property ?string $opened_by_user_id
 * @property string $reference
 * @property string $subject
 * @property TicketCategory $category
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property ?string $service_id
 * @property ?string $invoice_id
 * @property ?string $assigned_to_user_id
 * @property ?CarbonImmutable $last_reply_at
 * @property ?MessageAuthorKind $last_reply_by
 * @property ?CarbonImmutable $first_responded_at
 * @property ?CarbonImmutable $resolved_at
 * @property ?CarbonImmutable $closed_at
 * @property int $reopened_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    use HasUlids;

    public $incrementing = false;

    protected $table = 'support_tickets';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_by' => MessageAuthorKind::class,
            'last_reply_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reopened_count' => 'integer',
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
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    /**
     * The messages a customer may see.
     *
     * A scope rather than a filter in the resource: an internal note excluded
     * at render time is an internal note that leaks the first time somebody
     * writes an endpoint that returns messages without going through that
     * resource.
     *
     * @return HasMany<SupportMessage, $this>
     */
    public function customerVisibleMessages(): HasMany
    {
        return $this->messages()->where('is_internal_note', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::WaitingForSupport->value,
            TicketStatus::WaitingForCustomer->value,
        ]);
    }

    /**
     * The queue's order: worst first, then longest untouched.
     *
     * Ordered by the enum's own weighting rather than alphabetically — `high`
     * sorts before `low` and `normal` and `urgent` in a string ordering, which
     * would put the worst tickets third.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('coalesce(last_reply_at, created_at) asc')
            ->orderBy('id');
    }
}
