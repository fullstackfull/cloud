<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;

/**
 * @mixin CountryCurrencyChange
 */
final class CountryCurrencyChangeResource extends JsonResource
{
    /**
     * The stored impact with its blockers and warnings put into sentences, in
     * the language the request asked for. Counts are pluralised by the
     * catalogue, so "1 open invoice" and "3 open invoices" are both its
     * sentences and not string arithmetic here.
     *
     * @return array{facts: array<string, mixed>, blockers: list<string>, warnings: list<string>}
     */
    private function impactInWords(): array
    {
        $impact = $this->impact;

        return [
            'facts' => $impact['facts'],
            'blockers' => array_map(self::sentence('blockers'), $impact['blockers']),
            'warnings' => array_map(self::sentence('warnings'), $impact['warnings']),
        ];
    }

    /**
     * @return callable(array{code: string, params: array<string, scalar>}): string
     */
    private static function sentence(string $kind): callable
    {
        return static function (array $line) use ($kind): string {
            $key = 'account.country_currency_change.'.$kind.'.'.$line['code'];
            $params = array_map(static fn (mixed $value): string => (string) $value, $line['params']);

            if (array_key_exists('tax_after', $params)) {
                $params['tax_after'] = self::tax($params, 'tax_after');
                $params['tax_before'] = self::tax($params, 'tax_before');
            }

            if (array_key_exists('count', $params)) {
                return trans_choice($key, (int) $line['params']['count'], $params);
            }

            return (string) __($key, $params);
        };
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function tax(array $params, string $which): string
    {
        return $params[$which] === 'none'
            ? (string) __('account.country_currency_change.tax.none')
            : (string) __('account.country_currency_change.tax.rate', ['rate' => $params[$which.'_rate'], 'name' => $params[$which.'_name'] !== '' ? $params[$which.'_name'] : (string) __('account.country_currency_change.tax.unnamed')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'state' => $this->state->value,
            'is_open' => $this->state->isOpen(),
            'needs_attention' => $this->state->needsAttention(),
            'from_country' => $this->from_country,
            'to_country' => $this->to_country,
            'from_currency' => $this->from_currency,
            'to_currency' => $this->to_currency,
            'reason' => $this->reason,
            'impact' => $this->impactInWords(),
            'decision_note' => $this->decision_note,
            'analysed_at' => $this->analysed_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
