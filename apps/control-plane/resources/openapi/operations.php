<?php

declare(strict_types=1);

/*
 * One entry per route, keyed by the route's name.
 *
 * Every route the application registers under `api/` or `webhooks/` must
 * appear here. The generator refuses to write a document while any route is
 * missing, and the test refuses to pass while any entry names a route that
 * does not exist — so the document cannot describe an endpoint the platform
 * does not serve, and cannot omit one it does.
 *
 * `page` and `single` name the response envelope; `schema` names the resource
 * inside it. `status` overrides the default success code. `body` is the
 * request schema for a mutation. `query` adds parameters beyond paging.
 */

/** A resource returned inside `{"data": …}`. */
$one = static fn (string $schema, int $status = 200): array => [
    'envelope' => 'single', 'schema' => $schema, 'status' => $status,
];

/** A paginated collection: `{"data": […], "meta": {…}}`. */
$many = static fn (string $schema): array => ['envelope' => 'page', 'schema' => $schema];

/** A response with no body at all. */
$empty = static fn (int $status = 204): array => ['envelope' => 'none', 'status' => $status];

return [

    /* ---------------------------------------------------------------------
     | Authentication
     |
     | Unauthenticated by definition, and rate-limited harder than anything
     | else on the API: these are the endpoints a credential-stuffing run
     | reaches for.
     */

    'api.v1.register' => [
        'tag' => 'Authentication',
        'summary' => 'Create an account',
        'description' => 'Creates a user, their first billing account, and sends a verification email. Nothing that spends money works until the address is verified.',
        'auth' => false,
        'body' => ['name', 'email', 'password', 'password_confirmation'],
        'response' => $one('User', 201),
    ],
    'api.v1.login' => [
        'tag' => 'Authentication',
        'summary' => 'Sign in',
        'description' => <<<'TEXT'
        Answers identically for an unknown address and a wrong password — same
        status, same code, same wording, same work performed. Anything else is
        an oracle for which addresses have accounts.

        An account with a second factor gets a single-use challenge token and
        no session; complete it at `/api/v1/login/two-factor`.
        TEXT,
        'auth' => false,
        'body' => ['email', 'password'],
        'response' => $one('User'),
    ],
    'api.v1.login.two_factor' => [
        'tag' => 'Authentication',
        'summary' => 'Complete the two-factor challenge',
        'description' => 'Takes the challenge token from sign-in plus a TOTP code or a recovery code. A recovery code is consumed on use.',
        'auth' => false,
        'body' => ['challenge_token', 'code'],
        'response' => $one('User'),
    ],
    'api.v1.logout' => [
        'tag' => 'Authentication',
        'summary' => 'Sign out',
        'description' => 'Invalidates the session. A token-authenticated caller has the token they presented revoked instead, so "log out" means the same thing for both kinds of client.',
        'response' => $empty(),
    ],
    'api.v1.password.forgot' => [
        'tag' => 'Authentication',
        'summary' => 'Request a password reset link',
        'description' => 'Always answers the same way, whether or not the address has an account.',
        'auth' => false,
        'body' => ['email'],
        'response' => $empty(202),
    ],
    'api.v1.password.reset' => [
        'tag' => 'Authentication',
        'summary' => 'Reset a password with a link token',
        'description' => 'Revokes every other session and every API token: a reset is what somebody does when they believe they have been compromised.',
        'auth' => false,
        'body' => ['token', 'email', 'password', 'password_confirmation'],
        'response' => $empty(),
    ],
    'api.v1.verification.verify' => [
        'tag' => 'Authentication',
        'summary' => 'Verify an email address',
        'description' => 'The signed link from the verification email. Verifies and then redirects the browser to the portal, so the SPA never handles signed-URL validation.',
        'auth' => false,
        'response' => ['envelope' => 'redirect', 'status' => 302],
    ],
    'api.v1.verification.resend' => [
        'tag' => 'Authentication',
        'summary' => 'Resend the verification email',
        'response' => $empty(202),
    ],

    /* ---------------------------------------------------------------------
     | The signed-in principal
     */

    'api.v1.me' => ['tag' => 'Account', 'summary' => 'The signed-in user', 'response' => $one('User')],
    'api.v1.me.update' => [
        'tag' => 'Account',
        'summary' => 'Update name, language, time zone or phone',
        'body' => ['name', 'locale', 'timezone', 'phone'],
        'response' => $one('User'),
    ],
    'api.v1.me.password' => [
        'tag' => 'Account',
        'summary' => 'Change password',
        'description' => 'Requires the current password, and revokes every other session and API token.',
        'body' => ['current_password', 'password', 'password_confirmation'],
        'response' => $empty(),
    ],
    'api.v1.me.sessions' => ['tag' => 'Account', 'summary' => 'Signed-in devices', 'response' => $many('Session')],
    'api.v1.me.sessions.destroy' => ['tag' => 'Account', 'summary' => 'Sign one device out', 'response' => $empty()],
    'api.v1.me.sessions.destroy_others' => [
        'tag' => 'Account',
        'summary' => 'Sign every other device out',
        'description' => 'Requires the current password. Keeps the session making the request.',
        'body' => ['current_password'],
        'response' => $empty(),
    ],
    'api.v1.me.login_activity' => [
        'tag' => 'Account',
        'summary' => 'Recent sign-in attempts',
        'description' => 'Successes and failures both, because a customer needs to see the failures.',
        'response' => $many('LoginActivity'),
    ],
    'api.v1.me.2fa.enable' => [
        'tag' => 'Account',
        'summary' => 'Begin enabling a second factor',
        'description' => 'Returns the TOTP secret and its otpauth URI. Not enabled until confirmed.',
        'body' => ['current_password'],
        'response' => $one('TwoFactorSetup'),
    ],
    'api.v1.me.2fa.confirm' => [
        'tag' => 'Account',
        'summary' => 'Confirm the second factor',
        'description' => 'Proves the authenticator works before it is required, and returns the recovery codes — shown once.',
        'body' => ['code'],
        'response' => $one('RecoveryCodes'),
    ],
    'api.v1.me.2fa.disable' => [
        'tag' => 'Account',
        'summary' => 'Disable the second factor',
        'body' => ['current_password'],
        'response' => $empty(),
    ],
    'api.v1.me.2fa.recovery_codes' => [
        'tag' => 'Account',
        'summary' => 'Regenerate recovery codes',
        'description' => 'Invalidates the previous set. Shown once.',
        'body' => ['current_password'],
        'response' => $one('RecoveryCodes'),
    ],

    /* ---------------------------------------------------------------------
     | API tokens
     */

    'api.v1.me.api_tokens.index' => ['tag' => 'API tokens', 'summary' => 'List tokens', 'response' => $many('ApiToken')],
    'api.v1.me.api_tokens.store' => [
        'tag' => 'API tokens',
        'summary' => 'Create a token',
        'description' => 'The only response that ever carries the token value. The platform stores a hash and cannot show it again.',
        'body' => ['name', 'abilities', 'expires_at', 'rate_limit_per_minute', 'allowed_ip_ranges'],
        'response' => $one('IssuedApiToken', 201),
    ],
    'api.v1.me.api_tokens.destroy' => ['tag' => 'API tokens', 'summary' => 'Revoke a token', 'response' => $empty()],

    /* ---------------------------------------------------------------------
     | Catalogue
     */

    'api.v1.catalog.products.index' => [
        'tag' => 'Catalogue',
        'summary' => 'Products on sale',
        'query' => ['kind'],
        'response' => $many('Product'),
    ],
    'api.v1.catalog.products.show' => [
        'tag' => 'Catalogue',
        'summary' => 'One product and its plans',
        'description' => 'Addressable by slug or id. A withdrawn product is not found rather than shown as unavailable.',
        'response' => $one('Product'),
    ],
    'api.v1.catalog.plans.show' => [
        'tag' => 'Catalogue',
        'summary' => 'One plan, priced in the account\'s currency',
        'response' => $one('Plan'),
    ],

    /* ---------------------------------------------------------------------
     | Orders
     */

    'api.v1.orders.index' => ['tag' => 'Orders', 'summary' => 'List orders', 'query' => ['status'], 'response' => $many('Order')],
    'api.v1.orders.store' => [
        'tag' => 'Orders',
        'summary' => 'Place an order',
        'description' => <<<'TEXT'
        Prices the basket server-side and creates the order and its invoice.
        Nothing is provisioned here: provisioning follows a payment the
        platform has verified against the provider, never a browser arriving at
        a success URL.

        Send an `Idempotency-Key`. Without one, a retry after a timeout places a
        second order — and the platform's own fingerprint of the basket is a
        safety net, not a substitute.
        TEXT,
        'body' => ['lines', 'billing_period', 'coupon_code', 'notes'],
        'response' => $one('Order', 201),
    ],
    'api.v1.orders.show' => ['tag' => 'Orders', 'summary' => 'One order', 'response' => $one('Order')],
    'api.v1.orders.cancel' => [
        'tag' => 'Orders',
        'summary' => 'Cancel an unpaid order',
        'description' => 'Only while it is still cancellable. A paid order is refunded, not cancelled.',
        'response' => $one('Order'),
    ],

    /* ---------------------------------------------------------------------
     | Billing
     */

    'api.v1.invoices.index' => [
        'tag' => 'Billing',
        'summary' => 'List invoices',
        'description' => 'Drafts are never included: an invoice the platform has not issued is not a bill the customer owes.',
        'query' => ['status'],
        'response' => $many('Invoice'),
    ],
    'api.v1.invoices.show' => ['tag' => 'Billing', 'summary' => 'One invoice with its lines', 'response' => $one('Invoice')],
    'api.v1.invoices.payments.store' => [
        'tag' => 'Billing',
        'summary' => 'Start paying an invoice',
        'description' => <<<'TEXT'
        Creates a payment intent at the provider and returns what the client
        needs to complete it. The invoice is not settled here: it is settled
        when the platform has verified the capture server-side, whether that
        arrives by webhook or by a retrieve the platform performs itself.

        A capture that has already succeeded and not yet been applied blocks a
        second attempt, so a customer cannot be charged twice by clicking twice.
        TEXT,
        'body' => ['provider', 'return_url'],
        'response' => $one('StartedPayment', 201),
    ],
    'api.v1.subscriptions.index' => ['tag' => 'Billing', 'summary' => 'List subscriptions', 'query' => ['status'], 'response' => $many('Subscription')],
    'api.v1.subscriptions.show' => ['tag' => 'Billing', 'summary' => 'One subscription', 'response' => $one('Subscription')],
    'api.v1.subscriptions.cancel' => [
        'tag' => 'Billing',
        'summary' => 'Cancel a subscription',
        'description' => 'At period end by default, so the customer keeps what they have paid for.',
        'body' => ['immediately', 'reason'],
        'response' => $one('Subscription'),
    ],
    'api.v1.payments.index' => ['tag' => 'Billing', 'summary' => 'List payments', 'query' => ['status'], 'response' => $many('Payment')],
    'api.v1.payments.show' => ['tag' => 'Billing', 'summary' => 'One payment', 'response' => $one('Payment')],
    'api.v1.wallet.show' => [
        'tag' => 'Billing',
        'summary' => 'Wallet balances',
        'description' => 'One balance per currency. A customer who has paid in two currencies has two, and they are never added together.',
        'response' => ['envelope' => 'list', 'schema' => 'WalletBalance'],
    ],
    'api.v1.wallet.transactions.index' => ['tag' => 'Billing', 'summary' => 'Wallet history', 'response' => $many('WalletTransaction')],

    /* ---------------------------------------------------------------------
     | Services
     */

    'api.v1.services.index' => ['tag' => 'Services', 'summary' => 'List services', 'query' => ['kind', 'state'], 'response' => $many('Service')],
    'api.v1.services.show' => ['tag' => 'Services', 'summary' => 'One service', 'response' => $one('Service')],
    'api.v1.services.events.index' => [
        'tag' => 'Services',
        'summary' => 'What the platform has done to this service',
        'description' => 'Provisioning history, in the customer\'s vocabulary. Names no node, no cluster and no provider task.',
        'response' => $many('ProvisioningEvent'),
    ],

    /* ---------------------------------------------------------------------
     | Cloud VPS
     */

    'api.v1.vps.index' => ['tag' => 'Cloud VPS', 'summary' => 'List machines', 'query' => ['power_state'], 'response' => $many('VirtualMachine')],
    'api.v1.vps.show' => ['tag' => 'Cloud VPS', 'summary' => 'One machine', 'response' => $one('VirtualMachine')],
    'api.v1.vps.power' => [
        'tag' => 'Cloud VPS',
        'summary' => 'Start, stop, reboot or shut down',
        'description' => <<<'TEXT'
        Queues the operation and answers 202 with a handle on it. The machine's
        power state is not changed here — it is changed when the hypervisor says
        it was.

        Rate-limited well below the general API ceiling: a flood of distinct
        machine ids is the shape of a stolen token taking an account's fleet
        down.
        TEXT,
        'body' => ['action'],
        'response' => $one('ProvisioningOperation', 202),
    ],
    'api.v1.vps.reinstall' => [
        'tag' => 'Cloud VPS',
        'summary' => 'Reinstall a machine',
        'description' => 'Erases every disk. `confirm_hostname` must equal the machine\'s hostname — a boolean confirmation is a boolean a client library sends by default.',
        'body' => ['confirm_hostname', 'template_id', 'ssh_keys'],
        'response' => $one('ReinstallRequest', 202),
    ],
    'api.v1.vps.console' => [
        'tag' => 'Cloud VPS',
        'summary' => 'Open a console session',
        'description' => 'Mints a single-use credential that dies within a minute. Rate-limited so a loop cannot mint thousands of live permits.',
        'response' => $one('ConsoleSession', 201),
    ],

    /* ---------------------------------------------------------------------
     | Backups
     */

    'api.v1.backups.index' => [
        'tag' => 'Backups',
        'summary' => 'A machine\'s backups',
        'query' => ['state'],
        'response' => $many('Backup'),
    ],
    'api.v1.backups.store' => [
        'tag' => 'Backups',
        'summary' => 'Take a backup now',
        'description' => <<<'TEXT'
        Answers 202, not 201: the row exists and the backup does not. The
        provider has been asked and will be running for minutes to hours, and
        the state moves to `succeeded` only when the provider's task reports
        success.

        A request that times out becomes `needs_review` rather than `failed`.
        The hypervisor accepts a backup in milliseconds and runs it for an hour,
        so a timed-out call may well have started one — and retrying would run a
        second backup over the same disks.

        `mode` is the customer's decision because only they can weigh it:
        `snapshot` keeps the machine running and is only as consistent as the
        guest made it; `stop` is unambiguous and costs an outage.
        TEXT,
        'body' => ['mode', 'notes'],
        'response' => $one('Backup', 202),
    ],
    'api.v1.backups.show' => ['tag' => 'Backups', 'summary' => 'One backup', 'response' => $one('Backup')],

    /* ---------------------------------------------------------------------
     | Dedicated servers
     */

    'api.v1.dedicated.index' => ['tag' => 'Dedicated servers', 'summary' => 'List machines', 'response' => $many('DedicatedServer')],
    'api.v1.dedicated.show' => ['tag' => 'Dedicated servers', 'summary' => 'One machine and its components', 'response' => $one('DedicatedServer')],
    'api.v1.dedicated.power' => [
        'tag' => 'Dedicated servers',
        'summary' => 'Power control through the BMC',
        'description' => 'Queued. The BMC is on an isolated management network and is never reachable from here; nothing in the response names it.',
        'body' => ['action'],
        'response' => $one('ProvisioningOperation', 202),
    ],
    'api.v1.dedicated.reinstall' => [
        'tag' => 'Dedicated servers',
        'summary' => 'Reinstall over PXE',
        'description' => 'Erases every disk on a physical machine. Requires a typed confirmation, and only ever touches a machine the inventory declares as reinstallable.',
        'body' => ['confirm_serial', 'profile'],
        'response' => $one('ProvisioningOperation', 202),
    ],

    /* ---------------------------------------------------------------------
     | Shared hosting
     */

    'api.v1.hosting.index' => ['tag' => 'Shared hosting', 'summary' => 'List hosting accounts', 'response' => $many('HostingAccount')],
    'api.v1.hosting.show' => ['tag' => 'Shared hosting', 'summary' => 'One hosting account', 'response' => $one('HostingAccount')],
    'api.v1.hosting.usage' => [
        'tag' => 'Shared hosting',
        'summary' => 'Disk and bandwidth usage',
        'description' => 'As last measured. The platform does not ask the panel on every request: that would give every customer a way to load the node their neighbours are on.',
        'response' => $one('HostingAccountUsage'),
    ],
    'api.v1.hosting.sso' => [
        'tag' => 'Shared hosting',
        'summary' => 'Open a panel session',
        'description' => 'Mints a single-use sign-in URL at the panel. Never retried, including on a timeout: a second attempt is a second live session for the same account.',
        'response' => $one('HostingPanelSession', 201),
    ],

    /* ---------------------------------------------------------------------
     | IP addresses
     */

    'api.v1.ips.index' => ['tag' => 'IP addresses', 'summary' => 'Addresses assigned to this account', 'query' => ['service_id', 'ip_version'], 'response' => $many('IpAssignment')],
    'api.v1.ips.show' => ['tag' => 'IP addresses', 'summary' => 'One assignment', 'response' => $one('IpAssignment')],
    'api.v1.ips.rdns.update' => [
        'tag' => 'IP addresses',
        'summary' => 'Set reverse DNS',
        'description' => <<<'TEXT'
        Queued, and the record is not live until the provider says it is.

        Refused for an address that is not internet-routed — there is no
        delegated zone to write into — and refused with
        `ipam.reverse_dns_provider_unavailable` when the configured provider
        holds no reverse zone covering the address. Nothing is ever reported as
        published that was not published: a platform whose dashboard says a PTR
        is live while every receiver rejects the customer's mail is worse than
        one that says it cannot.
        TEXT,
        'body' => ['hostname'],
        'response' => $one('ReverseDnsRecord', 202),
    ],

    /* ---------------------------------------------------------------------
     | Webhooks
     */

    'webhooks.receive' => [
        'tag' => 'Webhooks',
        'summary' => 'Receive a provider webhook',
        'description' => <<<'TEXT'
        Called by the payment provider, not by a client. Outside the versioned
        API on purpose: the shape is the provider's, and it must never sit
        behind the session or CSRF middleware the portals use.

        The signature is verified before the body is parsed, and an event
        already seen is acknowledged without being applied again — a provider
        that delivers ten times must not settle an invoice ten times.
        TEXT,
        'auth' => false,
        'response' => $empty(204),
    ],

    /* ---------------------------------------------------------------------
     | Operator surface
     |
     | Same guard as the customer API, so the prefix protects nothing. What
     | separates them is that every route below names the permission it needs.
     */

    'api.admin.customers.index' => ['tag' => 'Operator', 'summary' => 'Search accounts', 'permission' => 'customer.view_any', 'query' => ['q', 'status'], 'response' => $many('AdminCustomer')],
    'api.admin.customers.show' => ['tag' => 'Operator', 'summary' => 'One account', 'permission' => 'customer.view', 'response' => $one('AdminCustomer')],
    'api.admin.customers.status' => [
        'tag' => 'Operator',
        'summary' => 'Suspend or reinstate an account',
        'description' => 'Its own permission, separate from update: stopping an account from buying and correcting a typo in its address are not the same decision.',
        'permission' => 'customer.suspend',
        'body' => ['status', 'reason'],
        'response' => $one('AdminCustomer'),
    ],
    'api.admin.provisioning.jobs' => ['tag' => 'Operator', 'summary' => 'The provisioning queue', 'permission' => 'provisioning.view', 'query' => ['status', 'kind'], 'response' => $many('AdminProvisioningJob')],
    'api.admin.provisioning.needs_review' => [
        'tag' => 'Operator',
        'summary' => 'Jobs waiting for a person',
        'description' => 'Where an indeterminate provider call goes. Nothing here is retried automatically, which is the point of the state.',
        'permission' => 'provisioning.view',
        'response' => $many('AdminProvisioningJob'),
    ],
    'api.admin.infrastructure.nodes' => ['tag' => 'Operator', 'summary' => 'Compute nodes and their capacity', 'permission' => 'infrastructure.view', 'response' => $many('AdminNode')],
    'api.admin.infrastructure.ip_pools' => ['tag' => 'Operator', 'summary' => 'Address pools', 'permission' => 'ipam.view', 'response' => $many('AdminIpPool')],
    'api.admin.infrastructure.dedicated' => ['tag' => 'Operator', 'summary' => 'Dedicated inventory', 'permission' => 'infrastructure.view', 'query' => ['status'], 'response' => $many('AdminDedicatedServer')],
    'api.admin.infrastructure.hosting_nodes' => [
        'tag' => 'Operator',
        'summary' => 'Hosting nodes',
        'description' => 'Names no API endpoint and no credentials reference: a support tool that displays them is a support tool that puts them in a screenshot.',
        'permission' => 'infrastructure.view',
        'response' => $many('AdminHostingNode'),
    ],
    'api.admin.invoices.index' => ['tag' => 'Operator', 'summary' => 'Invoices across accounts', 'permission' => 'invoice.view_any', 'query' => ['status'], 'response' => $many('AdminInvoice')],
    'api.admin.transactions.index' => ['tag' => 'Operator', 'summary' => 'Payments across accounts', 'permission' => 'payment.view_any', 'query' => ['status'], 'response' => $many('AdminTransaction')],
    'api.admin.transactions.refund' => [
        'tag' => 'Operator',
        'summary' => 'Refund a capture',
        'description' => 'Its own permission: moving money back out is not implied by being able to look at payments. Records who issued it, because "who refunded this?" has to survive the operator leaving.',
        'permission' => 'payment.refund',
        'body' => ['amount_minor', 'reason'],
        'response' => $one('AdminRefund', 201),
    ],
    'api.v1.backups.restore' => [
        'tag' => 'Backups',
        'summary' => 'Restore a backup over its machine',
        'description' => 'The most destructive operation the customer API offers. Send the machine\'s hostname exactly as `confirmation`; it is compared without case folding, because it is evidence a person read the screen rather than a lookup. Answers 202 - the disks are still being written. A provider that does not answer leaves the backup in needs_review and is never retried automatically.',
        'body' => ['confirmation'],
        'response' => $one('Backup', 202),
    ],
    'api.v1.me.notification_preferences.index' => [
        'tag' => 'Account',
        'summary' => 'Which optional messages this person wants',
        'description' => 'Every category is listed, including the ones that cannot be changed - a screen that omitted them would leave a customer wondering whether they had been switched off silently.',
        'response' => $many('NotificationPreference'),
    ],
    'api.v1.me.notification_preferences.update' => [
        'tag' => 'Account',
        'summary' => 'Turn an optional category on or off',
        'description' => 'Refuses a change it would not honour. A setting that appears to save and then does nothing is worse than one that says no.',
        'body' => ['category', 'channel', 'enabled'],
        'response' => $many('NotificationPreference'),
    ],
    'api.v1.notifications.index' => [
        'tag' => 'Notifications',
        'summary' => 'The customer\'s inbox',
        'description' => 'Newest first, with the unread count in the same response so the portal needs no second request per page load. Pass unread=true for only the unread ones.',
        // Paging is not listed here: a paged response envelope already
        // documents page and per_page, and naming them again produced two
        // parameters with the same name on one operation.
        'query' => ['unread'],
        'response' => $many('Notification'),
    ],
    'api.v1.notifications.read' => [
        'tag' => 'Notifications',
        'summary' => 'Mark one notification read',
        'description' => 'Idempotent, and it does not restamp: a second call must not move the time somebody actually read it.',
        'response' => $one('Notification'),
    ],
    'api.v1.notifications.read_all' => [
        'tag' => 'Notifications',
        'summary' => 'Mark every notification read',
        'response' => $one('NotificationsMarkedRead'),
    ],
    'api.v1.subscriptions.plan' => [
        'tag' => 'Billing',
        'summary' => 'Change a subscription\'s plan',
        'description' => 'Mid-cycle. The unused remainder of the old plan is credited and the same remainder charged at the new one, through one proration call - so an upgrade and an immediate downgrade net to zero. The billing anniversary does not move: a plan change is not a renewal.',
        'body' => ['plan_id', 'price_id', 'units'],
        'response' => $one('PlanChange'),
    ],
    'api.admin.invoices.void' => [
        'tag' => 'Operator',
        'summary' => 'Void an invoice',
        'description' => 'Moves no money, and the action refuses any invoice that has taken any - that is a refund, a different decision with a different permission. The reason is required and lands in the audit trail.',
        'permission' => 'invoice.manage',
        'body' => ['reason'],
        'response' => $one('AdminVoidedInvoice'),
    ],
    'api.admin.hosting_accounts.unsuspend' => [
        'tag' => 'Operator',
        'summary' => 'Put a suspended hosting account back into service',
        'description' => 'The manual way in. The automated one is the subscription listener - the customer pays and the account comes back.',
        'permission' => 'hosting_account.manage',
        'body' => ['reason'],
        'response' => $one('AdminHostingAccountState'),
    ],
    'api.admin.hosting_accounts.terminate' => [
        'tag' => 'Operator',
        'summary' => 'Terminate a hosting account',
        'description' => 'The retention window is enforced by the action. Skipping it with `force` additionally requires service.terminate: clearing out accounts whose retention has elapsed and deleting a live customer\'s site today are different decisions. Both are recorded in the audit trail, and distinguishably.',
        'permission' => 'hosting_account.manage',
        'body' => ['reason', 'force'],
        'response' => $one('AdminHostingAccountState'),
    ],
    'api.admin.provisioning.adopt' => [
        'tag' => 'Operator',
        'summary' => 'Adopt a resource the provider already built',
        'description' => 'The other half of never retrying a timeout: the platform stopped waiting, the provider may not have. The operator states the provider reference and the evidence they looked at, and both land in the audit trail. Refuses a running job, a settled one, and a reference another job already claims (409).',
        'permission' => 'provisioning.retry',
        'body' => ['provider_reference', 'remote_job_id', 'evidence'],
        'response' => $one('AdminAdoptedJob'),
    ],
    'api.admin.drift.index' => [
        'tag' => 'Operator',
        'summary' => 'Disagreements with the providers',
        'description' => 'Worst first, then oldest. Nothing here changes a provider: the reconciler records and a person decides.',
        'permission' => 'drift.view',
        'query' => ['status', 'kind', 'severity'],
        'response' => $many('AdminDrift'),
    ],
    'api.admin.drift.review' => [
        'tag' => 'Operator',
        'summary' => 'Record a verdict on a drift',
        'description' => 'Acknowledged means seen and still true; resolved means no longer true and requires an explanation. 409 if another operator got there first.',
        'permission' => 'drift.resolve',
        'body' => ['verdict', 'resolution'],
        'response' => $one('AdminDrift'),
    ],
    'api.admin.infrastructure.reconcile' => [
        'tag' => 'Operator',
        'summary' => 'Reconcile a cluster now',
        'description' => 'Queues the same job the scheduler queues every thirty minutes, not a faster path reserved for humans.',
        'permission' => 'infrastructure.manage',
        'response' => $one('AdminReconciliationRequest', 202),
    ],
    'api.admin.audit.index' => [
        'tag' => 'Operator',
        'summary' => 'The permanent record',
        'description' => 'Read-only by design. Filterable by action, actor, subject and account; deliberately not full-text searchable across context.',
        'permission' => 'audit.view',
        'query' => ['action', 'actor_id', 'customer_id', 'subject_id'],
        'response' => $many('AuditEntry'),
    ],
];
