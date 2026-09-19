<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * When the cancellation should take effect, and — for the form that cannot be
 * undone — proof that a person asked for it.
 *
 * The two answers are different commercial events, so the field is explicit
 * and the safe one is the default.
 *
 *  - Absent or false: the subscription stops renewing and ends when the period
 *    the customer has already paid for runs out. Service keeps running until
 *    then, because they own that time, and the platform can still put it back
 *    (CancelSubscription::revoke).
 *  - True: the subscription ends now. Service stops, the state machine has no
 *    edge back out of cancelled, and the remainder of the paid period is *not*
 *    refunded here — refunding is a separate operation with separate
 *    authorisation, and quietly issuing one from a cancel route would move
 *    money through an endpoint nobody reviewed as a refund.
 *
 * Defaulting to immediate would take back time the customer has paid for on a
 * mistyped request, so the default is the reversible one.
 *
 * `confirm_subscription_id` is required for the irreversible form, and it is a
 * string rather than a second boolean on purpose. This is the same rule the
 * reinstall surfaces apply — `confirm_hostname` on a VPS, `confirm_serial` on
 * a dedicated server — and for the same reason: `"immediately": true` on its
 * own is a field that every generated client sets in its constructor, that
 * every convenience wrapper defaults, and that a retry loop resends without a
 * person ever seeing it. Repeating the subscription's own id cannot be done by
 * accident.
 *
 * Whether the value names *this* subscription is decided by
 * CancelCustomerSubscription, not here, so an operator tool or a support
 * script calling the action directly is held to the same proof.
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

            /*
             * Only demanded for the destructive form. Asking for it on a
             * scheduled cancellation would train clients to send it always,
             * which is how a confirmation becomes a constant.
             */
            'confirm_subscription_id' => ['required_if_accepted:immediately', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_subscription_id.required_if_accepted' => __('validation.requests.subscription.immediate_cancellation_confirmation'),
        ];
    }

    public function immediately(): bool
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return filter_var($validated['immediately'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The confirmation as sent, unmodified — no trimming and no case folding.
     * It is not a lookup, it is a proof that a person read the screen.
     */
    public function confirmation(): ?string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $confirmation = $validated['confirm_subscription_id'] ?? null;

        return is_string($confirmation) ? $confirmation : null;
    }
}
