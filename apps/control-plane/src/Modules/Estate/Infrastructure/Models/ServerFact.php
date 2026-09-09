<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Estate\Domain\Enums\FactSource;

/**
 * One thing known about one machine, and where it was learned.
 *
 * Facts are appended rather than overwritten. The current value of a key is
 * the row with no superseded_at — enforced by a partial unique index, so there
 * is never a moment where a machine has two current answers for its RAM.
 *
 * The history exists to answer questions an operator asks after something has
 * gone wrong: when did this disk disappear, when did the firmware change, was
 * the serial always this. It is bounded by a retention sweep rather than kept
 * forever, because a fact table that grows without limit becomes a metrics
 * store nobody meant to build.
 *
 * @property string $id
 * @property string $key
 * @property ?string $value
 * @property FactSource $source
 */
class ServerFact extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => FactSource::class,
            'observed_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * The facts that are true now, rather than everything ever observed.
     *
     * A scope rather than a second relation on the server, because a relation
     * carrying a where clause is a query wearing a relation's name — and the
     * difference shows the moment somebody tries to eager-load it.
     *
     * @param  Builder<ServerFact>  $query
     * @return Builder<ServerFact>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }
}
