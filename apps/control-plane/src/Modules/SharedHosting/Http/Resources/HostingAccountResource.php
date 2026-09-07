<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * What a customer may see of their own hosting account.
 *
 * The interesting half of this class is what is on the model and is not here:
 *
 *  - **hosting_node_id, and anything reachable through it** — the node's
 *    hostname, its API endpoint, its panel version, its load, its
 *    credentials_reference, its disk figures. Which machine an account sits on
 *    is an operational fact about the platform *and about the other tenants
 *    sharing that machine*: publishing it lets a customer work out who their
 *    neighbours are, and lets anyone who has read a status page work out which
 *    customers a failing node has just taken down. The customer's handle on
 *    their hosting is the ULID and the domain, not the machine.
 *  - **credentials_reference / panel API tokens / any password** — there is no
 *    password column on this table by design, and the node's credential is a
 *    WHM root API token, which is root on a machine holding several hundred
 *    customers' websites, mail and databases.
 *  - **customer_id** — the acting customer's own id, resolved from the
 *    authenticated principal. Echoing it back invites clients to start sending
 *    it, and an account id in a request body is a request to act on somebody
 *    else's data.
 *  - **ip_address_id** — an internal IPAM row id, useless to a client and a
 *    handle on another module's table.
 *  - **panel_package_name** — the name the *panel* knows the package by. It is
 *    what createacct is given, it is shared across every customer on that
 *    package, and the platform's own slug is what a client should join on.
 *
 * `service_id` IS here: it is the acting customer's own service, it is how a
 * client joins this account to its invoices and its subscription, and it names
 * nothing outside the account.
 *
 * Usage figures are deliberately absent. They have their own endpoint because
 * they are a reading from a point in time and are meaningless without the
 * timestamp that says when — see {@see HostingAccountUsageResource}.
 *
 * @mixin HostingAccount
 */
final class HostingAccountResource extends JsonResource
{
    public function __construct(HostingAccount $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HostingAccount $account */
        $account = $this->resource;

        $package = $account->package;

        return [
            'id' => $account->id,
            'service_id' => $account->service_id,

            'username' => $account->username,
            'primary_domain' => $account->primary_domain,
            'status' => $account->status->value,

            /*
             * The platform's copy of what the package promises, which is what
             * the customer bought. The quotas the *node* enforces come from
             * the package of the same name on the panel; where the two
             * disagree the panel wins, which is why the usage endpoint reports
             * a reading and this reports an entitlement.
             */
            'package' => $package === null ? null : [
                'slug' => $package->slug,
                'disk_quota_mib' => $package->disk_quota_mib,
                'bandwidth_quota_mib' => $package->bandwidth_quota_mib,
                'max_addon_domains' => $package->max_addon_domains,
                'max_subdomains' => $package->max_subdomains,
                'max_databases' => $package->max_databases,
                'max_email_accounts' => $package->max_email_accounts,
            ],

            /*
             * Certificate state is reported separately from account state
             * because the two fail independently: an account can be perfectly
             * active with an expired certificate, which is the state customers
             * report as "my site is down" even though nothing has stopped.
             */
            'ssl' => [
                'status' => $account->ssl_status?->value,
                'expires_at' => $account->ssl_expires_at?->toIso8601String(),
            ],

            'suspended_at' => $account->suspended_at?->toIso8601String(),

            /*
             * The reason is already shown to the customer by the panel when
             * they try to sign in, so withholding it here would only mean they
             * read it somewhere less useful.
             */
            'suspension_reason' => $account->suspension_reason,

            /*
             * When a suspended account's data may be released. Stated plainly
             * rather than left implicit: a customer settling an invoice needs
             * to know how long they have, and "we kept it for a while" is not
             * an answer anybody can plan against.
             */
            'retention_releases_at' => $account->retentionReleasesAt()?->toIso8601String(),

            'terminated_at' => $account->terminated_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
