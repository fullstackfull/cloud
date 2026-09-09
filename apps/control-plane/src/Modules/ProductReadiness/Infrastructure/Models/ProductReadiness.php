<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * Where one product stands, as last assessed, with the evidence.
 *
 * Has no factory on purpose. A row here is a conclusion the engine reached,
 * and a test that wrote one directly would be testing a state the engine
 * never produced.
 */
class ProductReadiness extends Model
{
    use HasUlids;

    protected $table = 'product_readiness';

    protected $guarded = ['id'];

    protected $attributes = [
        'state' => 'not_ready',
    ];

    protected function casts(): array
    {
        return [
            'product' => Product::class,
            'state' => ProductReadinessState::class,
            'blocker' => BlockerReason::class,
            'requirements' => 'array',
            'dependencies' => 'array',
            'assessed_at' => 'immutable_datetime',
            'state_changed_at' => 'immutable_datetime',
            'declared_sellable_at' => 'immutable_datetime',
            'sellability_withdrawn_at' => 'immutable_datetime',
        ];
    }

    public function isDeclaredSellable(): bool
    {
        return $this->declared_sellable_at !== null && $this->sellability_withdrawn_at === null;
    }
}
