<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Casts a (minor units, currency) column pair to a Money value object.
 *
 * Usage on a model:
 *
 *     protected function casts(): array
 *     {
 *         return ['total' => MoneyCast::class.':total_minor,currency'];
 *     }
 *
 * The amount column is a bigint of minor units; the currency column is a
 * char(3). Neither is ever a floating point or decimal column, so no value can
 * be rounded by the database engine on the way in or out.
 *
 * Declared with `mixed` on the way in rather than Money: Eloquent hands a
 * cast whatever was assigned to the attribute, and the guard in set() —
 * which is the whole reason a scalar cannot become money by accident — is
 * only reachable, and only checkable, if the signature admits that.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn = 'amount_minor',
        private readonly string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes[$this->amountColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($minor === null || $currency === null) {
            return null;
        }

        return Money::ofMinor((int) $minor, (string) $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->amountColumn => null,
                $this->currencyColumn => null,
            ];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf(
                'Attribute [%s] on [%s] must be assigned a %s instance, got %s. '
                .'Money is never built implicitly from a scalar, because that is where rounding bugs come from.',
                $key,
                $model::class,
                Money::class,
                get_debug_type($value),
            ));
        }

        return [
            $this->amountColumn => $value->minorUnits(),
            $this->currencyColumn => $value->currency(),
        ];
    }
}
