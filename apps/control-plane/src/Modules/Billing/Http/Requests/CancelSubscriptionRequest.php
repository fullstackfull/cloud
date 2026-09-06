<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * When the cancellation should take effect.
 *
 * The two answers are different commercial events, so the field is explicit
 * and the safe one is the default.
 *
 *  - Absent or false: the subscription stops renewing and ends when the period
 *    the customer has already paid for runs out. Service keeps running until
 *    then, because they own that time.
 *  - True: the subscription ends now. Service stops, and the remainder of the
 *    paid period is *not* refunded here — refunding is a separate operation
 *    with separate authorisation, and quietly issuing one from a cancel route
 *    would move money through an endpoint nobody reviewed as a refund.
 *
 * Defaulting to immediate would take back time the customer has paid for on a
 * mistyped request, so the default is the reversible one.
 */
final class CancelSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'immediately' => ['sometimes', 'boolean'],
        ];
    }

    public function immediately(): bool
    {
        return $this->boolean('immediately');
    }
}
