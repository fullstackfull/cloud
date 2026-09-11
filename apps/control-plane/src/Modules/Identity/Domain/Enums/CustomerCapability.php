<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * What a customer can be told one of their team's roles is able to do.
 *
 * This is the published half of {@see CustomerRole::permissions()}, and the
 * two are kept equal by a gate rather than by care: a role explanation that
 * drifts from the authorization it describes is worse than no explanation,
 * because the customer then assigns a role on the strength of a sentence the
 * server does not honour.
 *
 * **Every case here names a permission that at least one endpoint actually
 * checks.** Two permissions the role model declares are deliberately absent:
 *
 *  - `customer.close` — there is no self-service account closure. Closing an
 *    account is a support conversation at launch, so a capability row saying
 *    "can close the account" would describe a button nobody has.
 *  - `billing.methods.manage` — there are no stored payment methods to manage.
 *    The gateway holds the instrument; the platform holds no card on file.
 *
 * Both are kept in the role model because they say what the tier is *for*, and
 * both are named in the gate's own allow-list so that a third one cannot be
 * added without somebody writing down why it is not published.
 *
 * The permission string is published alongside the customer-facing id. It is
 * the canonical machine value, it is what the API refuses with, and a customer
 * integrating against the platform can check the claim for themselves — which
 * is the whole point of deriving this from authorization instead of writing it
 * out by hand.
 */
enum CustomerCapability: string
{
    case ViewServices = 'view_services';
    case ManageServices = 'manage_services';
    case EndServices = 'end_services';
    case ViewBilling = 'view_billing';
    case PayInvoices = 'pay_invoices';
    case ManageMembers = 'manage_members';
    case ManageAccount = 'manage_account';
    case ManageApiTokens = 'manage_api_tokens';
    case AskSupport = 'ask_support';

    /**
     * The permission the server checks for this capability.
     *
     * One capability, one permission, no aggregation. A capability that
     * required two permissions would be a sentence the customer could only
     * half rely on.
     */
    public function permission(): string
    {
        return match ($this) {
            self::ViewServices => 'service.view',
            self::ManageServices => 'service.manage',
            self::EndServices => 'service.destroy',
            self::ViewBilling => 'billing.view',
            self::PayInvoices => 'billing.pay',
            self::ManageMembers => 'customer.members.manage',
            self::ManageAccount => 'customer.manage',
            self::ManageApiTokens => 'apikey.manage',
            self::AskSupport => 'support.manage',
        };
    }

    /**
     * Whether a role holds this capability, asked of the role model.
     *
     * Never a table of its own: this reads the same `permissions()` list the
     * authorization check reads, so "the screen says yes" and "the endpoint
     * says yes" cannot disagree.
     */
    public function heldBy(CustomerRole $role): bool
    {
        return $role->can($this->permission());
    }

    /**
     * The permission strings this enum publishes.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_map(static fn (self $case): string => $case->permission(), self::cases());
    }
}
