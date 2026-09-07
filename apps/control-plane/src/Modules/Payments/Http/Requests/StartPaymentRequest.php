<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a client may say when it starts paying an invoice.
 *
 * Note what is not here, because it is the whole security model of this
 * endpoint:
 *
 *  - **no amount.** What is collected is read from the invoice inside the
 *    transaction that checks the invoice may be collected at all. An `amount`
 *    in the body is not validated and rejected, it is simply never read, so
 *    there is no rule to relax later and no path by which a client can name
 *    its own price.
 *  - **no currency.** The invoice is denominated already, and a second
 *    currency arriving here could only be used to convert — which this
 *    platform does not do.
 *  - **no customer id and no invoice owner.** Which account is paying is
 *    decided by the acting-customer middleware, and which invoice is being
 *    paid is a path parameter looked up through that account.
 *  - **no status, no "paid", no provider reference.** Nothing a browser sends
 *    can settle a payment. Confirmation arrives from the provider, server to
 *    server.
 */
final class StartPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Where the provider sends the customer back to once they have
             * finished at its end. Constrained to an http(s) URL and bounded
             * in length: it is handed to a third party, and an unbounded
             * string that a provider will happily redirect a browser to is
             * worth validating even though the customer can only aim it at
             * themselves.
             */
            'return_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function returnUrl(): ?string
    {
        $url = $this->validated()['return_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
