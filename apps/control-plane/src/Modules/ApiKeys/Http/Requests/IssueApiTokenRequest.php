<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\ApiKeys\Application\Actions\IssueApiToken;

/**
 * What a caller may say when minting a token, which is deliberately little.
 *
 * Absent, and each for its own reason:
 *
 *  - customer_id — the account the token acts for is the account the request is
 *    acting for. A body field would let a caller mint a credential for an
 *    account they are not currently acting on.
 *  - tokenable / user_id — a token authenticates as its creator. Minting one
 *    "for" a colleague would be issuing a credential in their name that they
 *    cannot see in their own list.
 *  - abilities — nothing in the application checks them yet, so accepting them
 *    would advertise a scope that is not enforced. See IssueApiToken.
 */
final class IssueApiTokenRequest extends FormRequest
{
    /** How many CIDR entries one allow-list may carry. */
    private const int MAX_IP_RANGES = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The account password, re-entered inside an authenticated
             * session. A token outlives the session that minted it, survives a
             * logout and is not visible in a browser's session list, so a
             * stolen or borrowed session must not be enough to mint one. The
             * confirmation itself is performed in the controller, where a
             * failure also counts towards the lockout and is written to the
             * login history.
             */
            'current_password' => ['required', 'string'],

            // The label the customer will recognise this token by in the list
            // and in an incident. Not unique: two "ci" tokens are a customer's
            // business, and a uniqueness rule here would leak the existence of
            // a name a colleague chose.
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // Absent means "no expiry". A date in the past is refused rather
            // than accepted as an instantly dead token, because it is far more
            // likely to be a client timezone bug than an intention.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],

            'allowed_ip_ranges' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_IP_RANGES],
            'allowed_ip_ranges.*' => ['string', 'max:64', $this->ipRangeRule()],

            /*
             * Bounded here for a friendly 422, and bounded again inside the
             * action, which is the enforcement point. This field replaces the
             * tier default in the `api` limiter, so an unchecked value is a
             * customer switching off their own throttle.
             */
            'rate_limit_per_minute' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                'max:'.IssueApiToken::ceilingPerMinute(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => __('validation.requests.api_token.current_password_required'),
            'expires_at.after' => __('validation.requests.api_token.expires_in_future'),
            'rate_limit_per_minute.max' => sprintf(
                'A token may not be given a ceiling above %d requests per minute.',
                IssueApiToken::ceilingPerMinute(),
            ),
        ];
    }

    public function currentPassword(): string
    {
        return (string) $this->validated()['current_password'];
    }

    public function tokenName(): string
    {
        return trim((string) $this->validated()['name']);
    }

    public function expiresAt(): ?DateTimeInterface
    {
        $expiresAt = $this->validated()['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== ''
            ? CarbonImmutable::parse($expiresAt)
            : null;
    }

    /**
     * @return list<string>|null
     */
    public function allowedIpRanges(): ?array
    {
        $ranges = $this->validated()['allowed_ip_ranges'] ?? null;

        if (! is_array($ranges) || $ranges === []) {
            return null;
        }

        // De-duplicated and re-indexed: the column is a JSON list, and a
        // sparse or repeated array would round-trip as an object.
        return array_values(array_unique(array_map(
            static fn (mixed $range): string => trim((string) $range),
            $ranges,
        )));
    }

    public function rateLimitPerMinute(): ?int
    {
        $limit = $this->validated()['rate_limit_per_minute'] ?? null;

        return $limit === null || $limit === '' ? null : (int) $limit;
    }

    /**
     * An entry must be something IpUtils can actually evaluate.
     *
     * Laravel has no CIDR rule, and `ip` alone would accept "10.0.0.0" while
     * silently rejecting the "10.0.0.0/8" the customer meant. Anything that
     * does not parse is refused at the door rather than stored as an entry that
     * matches nothing — an allow-list with a typo in it fails closed at
     * authentication time, which is a support ticket that starts with "the
     * token stopped working" and no clue why.
     */
    private function ipRangeRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isAddressOrCidr(trim($value))) {
                $fail('Each entry must be an IP address or a CIDR block, for example 198.51.100.0/24.');
            }
        };
    }

    private static function isAddressOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (substr_count($value, '/') !== 1) {
            return false;
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $bits = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $prefix <= $bits;
    }
}
