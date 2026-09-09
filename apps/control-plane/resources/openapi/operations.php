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
    'api.v1.subscriptions.plan_options' => [
        'tag' => 'Billing',
        'summary' => 'What each plan would cost this subscription',
        'description' => 'Priced by the platform, through the same proration the confirmation performs. Plans that cannot be taken are listed with their reasons and without their prices — a smaller disk is refused outright, because shrinking one destroys data.',
        'response' => $many('PlanChangeQuote'),
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
    'api.v1.invoices.wallet_credit.quote' => [
        'tag' => 'Billing',
        'summary' => 'What stored credit would cover on this invoice',
        'description' => 'Three figures: what the customer holds in the invoice\'s currency, what this invoice would take of it, and what would still be owed. A quote rather than a promise — a renewal can spend the balance in between, so the payment recomputes everything under a lock.',
        'response' => $one('WalletCreditQuote'),
    ],
    'api.v1.invoices.wallet_credit.pay' => [
        'tag' => 'Billing',
        'summary' => 'Pay an invoice from stored credit',
        'description' => 'Requires an Idempotency-Key: a repeated submission that debited twice would spend a balance the customer only has once. There is no amount field — how much is applied is decided from the balance and the amount due, both read under a lock. Partial payment is ordinary: the remainder stays payable by card. Credit is never converted between currencies.',
        'response' => $one('Invoice'),
    ],

    'api.v1.backups.destroy' => [
        'tag' => 'Backups',
        'summary' => 'Delete a backup',
        'description' => "Requires the backup's own id typed back, and `service.destroy` rather than `service.manage` — a technical contact who may rebuild a machine may not destroy the thing that would let it be rebuilt afterwards. Records a decision and calls no provider: the retention sweep acts on it after a grace period, so the response says `delete_requested`, never `deleted`. Refused while a restore is running, while the backup is still being written, when the plan sells retention as a guarantee, and while a termination hold is in force.",
        'body' => ['confirm_backup_id'],
        'response' => $one('Backup'),
    ],
    'api.v1.backups.keep' => [
        'tag' => 'Backups',
        'summary' => 'Call off a deletion',
        'description' => 'Only while the request is still waiting for the sweep. Once the provider has been asked there is nothing to call off, and pretending otherwise would leave a row reading `succeeded` for an archive that is being removed.',
        'response' => $one('Backup'),
    ],

    /* ---------------------------------------------------------------------
     | DNS
     |
     | Claiming a zone is not verifying a domain, and nothing on this surface
     | says otherwise. The platform cannot establish that an account owns a
     | name — what settles it is the delegation the customer makes at their
     | registrar, and until they make it the zone serves nobody.
     |
     | Validation is the platform's own rather than the provider's. A provider
     | will accept an AAAA holding an IPv4 address, a CNAME beside an MX, or an
     | MX pointing at an address; each of those is then discovered by the
     | customer as an outage rather than by the platform as an error.
     */

    'api.v1.dns.zones.index' => [
        'tag' => 'DNS',
        'summary' => "List this account's zones",
        'description' => 'Alphabetical, with a live record count. The provider holding the zone is not named: which third party this platform buys DNS from is its own arrangement.',
        'response' => ['envelope' => 'list', 'schema' => 'DnsZone'],
    ],
    'api.v1.dns.zones.store' => [
        'tag' => 'DNS',
        'summary' => 'Claim a domain',
        'description' => "Creates the zone and answers with the nameservers to delegate to. **This is not verification.** Nothing here checks that the account owns the domain, and no field on the response should be read as saying so — the zone serves nothing until the registrar points the domain at those nameservers, which only whoever controls the registration can do. Refused for a domain another account already holds here, for the platform's own names and their parents, and for reverse zones, which follow the address block rather than the domain.",
        'body' => ['name', 'service_id'],
        'response' => $one('DnsZone', 201),
    ],
    'api.v1.dns.zones.show' => [
        'tag' => 'DNS',
        'summary' => 'Read one zone',
        'description' => 'A zone belonging to another account answers 404 rather than 403: a 403 would confirm which account holds which domain.',
        'response' => $one('DnsZone'),
    ],
    'api.v1.dns.zones.destroy' => [
        'tag' => 'DNS',
        'summary' => 'Give a domain up',
        'description' => 'Takes the domain name typed back, compared with `hash_equals`. The most destructive call on the customer surface: a zone that is gone answers NXDOMAIN for every name under it at once, including names this platform never wrote. There is no grace period — a backup sits on a datastore while somebody thinks, but a zone is being served, and a delayed removal would be an outage scheduled for a time the customer cannot see.',
        'body' => ['confirm_zone_name'],
        'response' => $one('DnsZone'),
    ],
    /* ---------------------------------------------------------------------
     | Domains
     |
     | The one product on this platform that cannot be repossessed. A registry
     | fee is spent the moment a registration succeeds, so every path here
     | invoices first and asks the registrar only once the money has arrived —
     | and every path that spends money refuses to act on an answer a registrar
     | never gave.
     */

    'api.v1.domains.search' => [
        'tag' => 'Domains',
        'summary' => 'Search for a name',
        'description' => "Answers with all five availability states, `unknown` among them. A registrar that times out has said nothing: rendering that as available invites a customer to buy a name that is taken and get a refusal after their money moved, and rendering it as unavailable turns away a customer who could have had it. The price beside each answer is the catalogue's and is not a commitment — what the platform will honour is a quote.",
        'query' => ['name', 'also_try'],
        'response' => ['envelope' => 'list', 'schema' => 'DomainSearchResult'],
    ],
    'api.v1.domains.quotes.store' => [
        'tag' => 'Domains',
        'summary' => 'Ask what a name will cost',
        'description' => 'Writes a price the platform will honour and answers with its id. There is no price field on the request and there will not be one: a premium name can cost a hundred times the list price for its namespace, and a checkout that accepted an amount from the client would sell it for the price of an ordinary one. The registrar is asked again here rather than trusting whatever the search put on the screen.',
        'body' => ['name', 'operation', 'term_years'],
        'response' => $one('DomainQuote', 201),
    ],
    'api.v1.domains.index' => [
        'tag' => 'Domains',
        'summary' => "List this account's names",
        'response' => ['envelope' => 'list', 'schema' => 'Domain'],
    ],
    'api.v1.domains.show' => [
        'tag' => 'Domains',
        'summary' => 'Read one name',
        'description' => 'A domain belonging to another account answers 404 rather than 403: a 403 would confirm which account holds which name.',
        'response' => $one('Domain'),
    ],
    'api.v1.domains.store' => [
        'tag' => 'Domains',
        'summary' => 'Register a name',
        'description' => 'Spends a quote, claims the name on this platform, records the registrant and issues an invoice. Nothing is registered until that invoice is paid. The registrant is required because every registry files a registration against one, and a registration submitted without it is refused after the customer has paid. A name another account already holds here answers 409.',
        'body' => ['quote_id', 'registrant'],
        'response' => $one('DomainOperation', 201),
    ],
    'api.v1.domains.renewals.store' => [
        'tag' => 'Domains',
        'summary' => 'Renew a name',
        'description' => 'Issues an invoice; the registry is asked when it is paid, by the same listener that handles an automatic renewal. A second renewal while one is in flight answers 409 — two renewals for one name is two years bought, and registries do not give the extra one back.',
        'body' => ['quote_id'],
        'response' => $one('DomainOperation', 201),
    ],
    'api.v1.domains.transfers.store' => [
        'tag' => 'Domains',
        'summary' => 'Transfer a name in',
        'description' => "Takes the authorisation code from the losing registrar. The code is held encrypted between payment and dispatch, erased the moment it is sent, and never appears in any response. A started transfer sits in `awaiting_registry` until the losing registrar releases it, which can take five days: that is the registry's design and not a failure.",
        'body' => ['quote_id', 'authorisation_code'],
        'response' => $one('DomainOperation', 201),
    ],
    'api.v1.domains.nameservers.update' => [
        'tag' => 'Domains',
        'summary' => 'Change the delegation',
        'description' => 'Between two and thirteen hosts, which is what registries enforce. Written at the registry first and recorded second: a stored delegation that ran ahead of the registry would send the customer debugging their own DNS. A registrar that times out leaves the stored copy alone and marks it for reconciliation rather than guessing.',
        'body' => ['nameservers'],
        'response' => $one('Domain'),
    ],
    'api.v1.domains.contacts.update' => [
        'tag' => 'Domains',
        'summary' => 'Change the registrant',
        'description' => "The registry's copy is the one that decides disputes and transfers, so it is written first and the stored rows follow. Every field is personal data, encrypted at rest, and never published on any surface but this account's own.",
        'body' => ['registrant'],
        'response' => $one('Domain'),
    ],
    'api.v1.domains.transfer_lock.update' => [
        'tag' => 'Domains',
        'summary' => 'Lock or unlock the name',
        'description' => 'The registrar lock is the single most effective defence a domain has against being stolen. Unlocking is not made difficult — the customer owns the name — but it is recorded, because unlocking and taking the authorisation code are the two steps by which a name leaves.',
        'body' => ['locked'],
        'response' => $one('Domain'),
    ],
    'api.v1.domains.authorisation_code.store' => [
        'tag' => 'Domains',
        'summary' => 'Take the authorisation code',
        'description' => 'The code that lets the customer move this name to another registrar. A POST rather than a GET because it is an act: most registrars regenerate the code when asked, and a GET would be repeated by a browser prefetch. The code is never stored by this platform and never written to the audit trail — only the fact that somebody asked for it.',
        'response' => $one('DomainAuthorisationCode'),
    ],

    'api.v1.dns.records.index' => [
        'tag' => 'DNS',
        'summary' => 'List the records in a zone',
        'description' => 'What this platform has published, which is not the same as what the zone serves. Records added through the provider\'s own console are not here; reconciliation reports them and never removes them.',
        'response' => ['envelope' => 'list', 'schema' => 'DnsRecord'],
    ],
    'api.v1.dns.records.store' => [
        'tag' => 'DNS',
        'summary' => 'Add a record',
        'description' => 'A, AAAA, CNAME, MX, TXT and CAA. CAA carries `data.flags`, `data.tag` and `data.value` instead of `content`. Refused for a name outside the zone, an address of the wrong family, an address no visitor could reach, an address this platform allocates to another account, a CNAME at the apex or beside another record, an MX without a priority or pointing at an address, and a value already published under that name.',
        'body' => ['type', 'name', 'content', 'ttl', 'priority', 'data'],
        'response' => $one('DnsRecord', 201),
    ],
    'api.v1.dns.records.update' => [
        'tag' => 'DNS',
        'summary' => 'Change what a record says',
        'description' => 'The name and type are fixed; a record with a different name is a different record. The row returns to `pending` while the change is published rather than being written over, because a screen showing the new value as live before the provider has taken it would tell a customer their site had moved when it had not. Every rule the create path applies is applied again — an edit is the obvious way round a check that only guards creation.',
        'body' => ['content', 'ttl', 'priority', 'data'],
        'response' => $one('DnsRecord'),
    ],
    'api.v1.dns.records.destroy' => [
        'tag' => 'DNS',
        'summary' => 'Remove a record',
        'description' => 'A record that never reached the provider is simply gone. One that did is marked `deleting` and a worker asks; if the provider does not answer, the row is left `indeterminate` rather than `deleted` and reconciliation settles it by looking — asking again is how a name the customer has since re-created gets removed a second time.',
        'response' => $one('DnsRecord'),
    ],

    /* ---------------------------------------------------------------------
     | Support
     |
     | A customer may open, read, reply and close. They may not resolve:
     | resolved is the support team's opinion that the problem is solved, and
     | closed is the account saying it is finished with the conversation.
     |
     | Internal notes are excluded in the query rather than in the serialiser,
     | so no customer response can carry one.
     */

    'api.v1.support.index' => [
        'tag' => 'Support',
        'summary' => 'List this account\'s tickets',
        'description' => 'Most recently active first. Internal notes are never included.',
        'response' => ['envelope' => 'list', 'schema' => 'Ticket'],
    ],
    'api.v1.support.store' => [
        'tag' => 'Support',
        'summary' => 'Open a ticket',
        'description' => 'Multipart when it carries attachments. `priority` accepts low, normal or high — urgent is what pages somebody out of hours and is an operator\'s judgement to make. `service_id` and `invoice_id` must belong to the acting account; one that does not answers 404 rather than being silently dropped.',
        'body' => ['subject', 'body', 'category', 'priority', 'service_id', 'invoice_id', 'attachments'],
        'response' => $one('Ticket', 201),
    ],
    'api.v1.support.show' => [
        'tag' => 'Support',
        'summary' => 'Read one ticket and its thread',
        'response' => $one('Ticket'),
    ],
    'api.v1.support.reply' => [
        'tag' => 'Support',
        'summary' => 'Reply on a ticket',
        'description' => 'Moves the ticket to the support team\'s turn. A reply to a resolved ticket reopens it with its history intact; a closed one refuses.',
        'body' => ['body', 'attachments'],
        'response' => $one('Ticket'),
    ],
    'api.v1.support.close' => [
        'tag' => 'Support',
        'summary' => 'Close a ticket',
        'description' => 'The end of the conversation. Nothing may be written on a closed ticket by either side; a customer with something else to say opens a new one and quotes the reference.',
        'response' => $one('Ticket'),
    ],
    'api.v1.support.attachments.download' => [
        'tag' => 'Support',
        'summary' => 'Download an attachment',
        'description' => 'Streamed with Content-Disposition: attachment and X-Content-Type-Options: nosniff, so a browser saves the file rather than rendering it. An attachment on an internal note is not found.',
        'response' => ['envelope' => 'none', 'status' => 200],
    ],

    'api.admin.support.tickets' => [
        'tag' => 'Operator',
        'summary' => 'The support queue',
        'description' => 'Worst first, longest untouched first within that. Defaults to the tickets waiting on the team rather than to every ticket ever raised. `?status=` and `?assigned_to=` narrow it.',
        'permission' => 'ticket.view_any',
        'response' => ['envelope' => 'list', 'schema' => 'OperatorTicket'],
    ],
    'api.admin.support.ticket' => [
        'tag' => 'Operator',
        'summary' => 'One ticket, with internal notes',
        'permission' => 'ticket.view_any',
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.attachments.download' => [
        'tag' => 'Operator',
        'summary' => 'Download any attachment on any ticket',
        'permission' => 'ticket.view_any',
        'response' => ['envelope' => 'none', 'status' => 200],
    ],
    'api.admin.support.ticket_reply' => [
        'tag' => 'Operator',
        'summary' => 'Reply, or write an internal note',
        'description' => '`internal_note: true` writes a message the customer never sees and which does not move the ticket to their turn — the clock on an unanswered question keeps running, which is the point.',
        'permission' => 'ticket.reply',
        'body' => ['body', 'internal_note', 'attachments'],
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.ticket_assign' => [
        'tag' => 'Operator',
        'summary' => 'Assign a ticket, or unassign it',
        'description' => 'A null user_id returns it to the unassigned pool. Audited.',
        'permission' => 'ticket.manage',
        'body' => ['user_id'],
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.ticket_priority' => [
        'tag' => 'Operator',
        'summary' => 'Change a ticket\'s priority',
        'description' => 'The whole scale including urgent, unlike the customer surface. Audited, because quietly downgrading an urgent ticket is how one stops being looked at.',
        'permission' => 'ticket.manage',
        'body' => ['priority'],
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.ticket_resolve' => [
        'tag' => 'Operator',
        'summary' => 'Mark a ticket resolved',
        'description' => 'The team\'s opinion that the problem is solved. The customer is told, and a reply from them reopens it with its history.',
        'permission' => 'ticket.manage',
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.ticket_close' => [
        'tag' => 'Operator',
        'summary' => 'Close a ticket',
        'permission' => 'ticket.manage',
        'response' => $one('OperatorTicket'),
    ],
    'api.admin.support.ticket_reopen' => [
        'tag' => 'Operator',
        'summary' => 'Reopen a resolved or closed ticket',
        'description' => 'Without writing a message — for an operator who resolved one by mistake and has nothing to say to the customer.',
        'permission' => 'ticket.manage',
        'response' => $one('OperatorTicket'),
    ],

    /* ---------------------------------------------------------------------
     | Team
     |
     | Who belongs to the acting customer's account. No customer id appears in
     | any path: the account is the one the caller is already acting for.
     |
     | The three invitation endpoints under /invitations are the invitee's
     | side, and are the only customer endpoints that do not require an acting
     | account - the person accepting their first invitation belongs to none.
     */

    'api.v1.team.members' => [
        'tag' => 'Team',
        'summary' => 'List the people in this account',
        'description' => 'Any member may read it. Somebody who cannot see who has access to their servers is worse off than somebody who can.',
        'response' => ['envelope' => 'list', 'schema' => 'TeamMember'],
    ],
    'api.v1.team.members.role' => [
        'tag' => 'Team',
        'summary' => "Change a member's role",
        'description' => 'Takes effect on the next request: the role is read from the membership row every time and nothing caches it. `owner` is refused - ownership is transferred, not granted.',
        'body' => ['role'],
        'response' => $one('TeamMember'),
    ],
    'api.v1.team.members.remove' => [
        'tag' => 'Team',
        'summary' => 'Remove somebody from this account',
        'description' => 'Also deletes any API token they hold that was scoped to this account. Tokens they hold for other accounts are untouched. The owner cannot be removed.',
        'response' => $empty(),
    ],
    'api.v1.team.invitations' => [
        'tag' => 'Team',
        'summary' => 'List invitations',
        'description' => 'Including spent ones, so that "I invited them and nothing happened" is answered by seeing the offer was declined rather than by an empty list. No token is ever returned.',
        'response' => ['envelope' => 'list', 'schema' => 'TeamInvitation'],
    ],
    'api.v1.team.invitations.create' => [
        'tag' => 'Team',
        'summary' => 'Invite somebody to this account',
        'description' => 'The response is identical whether or not the address already has a Lynomia login: the difference is exactly what an attacker would be fishing for. The token goes only to the address, never into this response.',
        'body' => ['email', 'role'],
        'response' => $one('TeamInvitation', 201),
    ],
    'api.v1.team.invitations.resend' => [
        'tag' => 'Team',
        'summary' => 'Send an invitation again',
        'description' => 'Mints a new token and pushes the expiry out; the previous link stops working. The platform stores a hash rather than the token, so it cannot repeat a link it never kept.',
        'response' => $one('TeamInvitation'),
    ],
    'api.v1.team.invitations.revoke' => [
        'tag' => 'Team',
        'summary' => 'Withdraw an invitation',
        'description' => 'Taken under a row lock, so an acceptance already in flight cannot commit a membership after the offer was withdrawn.',
        'response' => $one('TeamInvitation'),
    ],
    'api.v1.team.transfer_ownership' => [
        'tag' => 'Team',
        'summary' => 'Hand this account to another member',
        'description' => "One act, both sides: the outgoing owner becomes an administrator and the incoming one becomes owner, in one transaction, so the account is never ownerless and never owned twice. Only the current owner may ask, the successor must already be an accepted member, and the account's own id must be typed back.",
        'body' => ['member_id', 'confirm_account_id'],
        'response' => $one('TeamMember'),
    ],
    'api.v1.invitations.show' => [
        'tag' => 'Team',
        'summary' => 'Look at an invitation you were sent',
        'description' => 'Selected by the token from the mail, which is the only selector: there is no endpoint that lists invitations by address, because that would answer "is this person being invited anywhere" about somebody else. A token that names nothing and one that names a spent offer answer identically.',
        'response' => $one('InvitationOffer'),
    ],
    'api.v1.invitations.accept' => [
        'tag' => 'Team',
        'summary' => 'Accept an invitation',
        'description' => 'Requires that the signed-in address is the one invited and that it has been verified. A forwarded invitation admits nobody: the mail is a notification, the address is the credential.',
        'response' => $one('AcceptedInvitation', 201),
    ],
    'api.v1.invitations.decline' => [
        'tag' => 'Team',
        'summary' => 'Decline an invitation',
        'description' => 'Held to the same address check as acceptance: declining closes the offer, so a stranger who could decline could keep a colleague out for ever.',
        'response' => $empty(),
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
    'api.admin.provisioning.retry' => [
        'tag' => 'Operator',
        'summary' => 'Run a stopped job again',
        'description' => 'The safe half of recovery: a build that failed on a full cluster or a control panel that was briefly down will succeed on a second run. Refuses (409) a job that has not stopped, one that already has a resource at the provider — adopt that instead — and any operation that has already destroyed data. The evidence the operator checked is required and audited.',
        'permission' => 'provisioning.retry',
        'body' => ['evidence'],
        'response' => $one('AdminRetriedJob'),
    ],
    'api.admin.operations.reinstalls' => [
        'tag' => 'Operator',
        'summary' => 'Rebuilds, running and stopped',
        'description' => 'Both reinstall lifecycles in one queue, separated by `type`. `data_destroyed` answers the question a customer rings about, from the timestamp rather than from the state. Filter with `needs_attention=1` for the ones nobody can settle. The two tables are read to `meta.merge_limit` rows each and merged; `meta.truncated` says when that bound was reached.',
        'permission' => 'provisioning.view',
        'query' => ['type', 'state', 'customer_id', 'needs_attention'],
        'response' => $many('AdminReinstallOperation'),
    ],
    'api.admin.operations.reinstall' => [
        'tag' => 'Operator',
        'summary' => 'One rebuild, and everything that happened to it',
        'description' => 'The operation, the provisioning job with its attempt log, and the audit entries for the machine — who asked for the rebuild, what the worker tried, what the provider said, and what a person decided afterwards.',
        'permission' => 'provisioning.view',
        'response' => $one('AdminReinstallHistory'),
    ],
    'api.admin.operations.reinstall_resolve' => [
        'tag' => 'Operator',
        'summary' => 'Record a verdict on a rebuild',
        'description' => 'For an operation waiting for one — `needs_review`, `indeterminate` or a provisioning timeout — and no other. `completed` or `failed`, with what the operator saw; both are edges the state machine already has, both settle the job the operation belonged to, and both tell the customer. There is no verdict for an operation still in flight and nothing here touches a hypervisor or a controller.',
        'permission' => 'provisioning.retry',
        'body' => ['verdict', 'evidence'],
        'response' => $one('AdminReinstallVerdict'),
    ],
    'api.admin.services.terminate' => [
        'tag' => 'Operator',
        'summary' => 'End a service and destroy its machine',
        'description' => 'The one act on this surface that destroys data. The retention window on a suspended service is enforced by the action, not by the caller: most suspensions are billing disputes that end with the customer paying. `force` skips it for an abuse case or a right-to-erasure request, needs the terminate permission checked a second time, and is recorded as forced. Answers 202. For a VPS the machine is destroyed by a worker, and the service reaches `terminated` when that worker succeeds, releasing the address into quarantine and the capacity back to the node. For a dedicated server nothing is queued: the machine leaves the customer and is held in `maintenance` until an operator states its disks have been erased, because no call this platform can make proves that they were.',
        'permission' => 'service.terminate',
        'body' => ['reason', 'force'],
        'response' => $one('AdminTerminatedService', 202),
    ],
    'api.admin.dedicated.return_to_stock' => [
        'tag' => 'Operator',
        'summary' => 'Put a decommissioned machine back on the shelf',
        'description' => 'The second half of ending a dedicated service, separate on purpose: nothing this platform can call proves that a physical disk was erased, so what this records is a person’s word for it, with what they did, beside their name, in the audit trail. Refuses a server still assigned to a customer (409) — that machine has not been decommissioned, whatever its status column says.',
        'permission' => 'dedicated.manage',
        'body' => ['evidence'],
        'response' => $one('AdminReturnedServer'),
    ],
    /*
     * The domain queues, from the operator's side. Read-only: an operator who
     * needs to renew or re-point a customer's name does it through the
     * customer paths, so one set of rules about money and the Timeout Rule
     * applies to everybody.
     */
    /* ---------------------------------------------------------------------
     | WordPress
     |
     | Shared hosting with WordPress on it, which is four things that have to
     | line up: an account, a name pointed at it, a certificate, and an
     | installation that answers. The state says which of the four is
     | outstanding, because that decides whether the customer should wait,
     | change their DNS, or talk to support.
     */

    'api.v1.wordpress.sites.index' => [
        'tag' => 'WordPress',
        'summary' => "List this account's sites",
        'response' => ['envelope' => 'list', 'schema' => 'WordPressSite'],
    ],
    'api.v1.wordpress.sites.store' => [
        'tag' => 'WordPress',
        'summary' => 'Order a WordPress site',
        'description' => "Takes the name and where it comes from. `existing` is refused unless this account actually holds the name here — downgrading it silently to `external` would leave the customer waiting for a delegation nobody is going to make. There is no password field: the administrator's password is generated by the platform, shown once, and never stored.",
        'body' => ['domain', 'domain_source', 'admin_username', 'admin_email', 'locale'],
        'response' => $one('WordPressSite', 201),
    ],
    'api.v1.wordpress.sites.show' => [
        'tag' => 'WordPress',
        'summary' => 'Read one site',
        'description' => 'A site belonging to another account answers 404 rather than 403.',
        'response' => $one('WordPressSite'),
    ],

    'api.admin.domains.index' => [
        'tag' => 'Operator',
        'summary' => 'Names the platform holds',
        'description' => 'Soonest expiry first. `needs_attention` narrows it to the names the Timeout Rule left behind that reconciliation could not settle — the queue a person has to work, because nothing else will clear it. Unlike the customer surface, this publishes which registrar holds the name and its reference there.',
        'permission' => 'service.view_any',
        'query' => ['state', 'customer_id', 'needs_attention', 'expiring_within_days'],
        'response' => $many('AdminDomain'),
    ],
    'api.admin.domains.operations' => [
        'tag' => 'Operator',
        'summary' => 'Registrations, renewals and transfers',
        'description' => 'Newest first. `needs_attention` narrows it to money spent on an outcome nobody has established, plus transfers still sitting with a losing registrar. Both the price and the cost are published here, because the question being answered is usually what a name cost the platform against what it charged.',
        'permission' => 'service.view_any',
        'query' => ['state', 'kind', 'needs_attention'],
        'response' => $many('AdminDomainOperation'),
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
    /* ---------------------------------------------------------------------
     | Infrastructure — the machines, and what Lynomia may do to them
     |
     | Two decisions live here and are deliberately not one endpoint. The
     | classification says what KIND of thing a machine is; the reimage
     | clearance says that one specific machine has been signed off for a
     | destructive action. An operator can hold the permission to do the first
     | without holding the permission to do the second, and the routes are
     | separated so that stays true.
     |
     | Nothing here returns a credential. A machine names the credential it
     | uses; the value is resolved inside the deployment controller's process
     | environment and never crosses this API.
     */

    'api.admin.infrastructure.servers.index' => [
        'tag' => 'Operator',
        'summary' => 'Managed machines',
        'description' => 'Ordered so the machines nobody has decided about come first: unclassified and '
            .'unreachable hardware is what an onboarding screen needs to show, not what already works.',
        'permission' => 'infrastructure.view',
        'query' => ['environment', 'state', 'safety_class'],
        'response' => $many('Server'),
    ],
    'api.admin.infrastructure.servers.show' => [
        'tag' => 'Operator',
        'summary' => 'One machine',
        'description' => 'Includes what the current classification permits, so a screen can disable an action '
            .'rather than offer it and have it refused.',
        'permission' => 'infrastructure.view',
        'response' => $one('Server'),
    ],
    'api.admin.infrastructure.servers.store' => [
        'tag' => 'Operator',
        'summary' => 'Register a machine',
        'description' => 'Registration records that a machine exists. It never classifies it: everything arrives '
            .'do_not_touch and untested, and is raised by a separate, separately-permissioned decision.',
        'permission' => 'infrastructure.manage',
        'body' => [
            'name', 'environment', 'datacenter_id', 'rack_id', 'rack_unit', 'height_units',
            'vendor', 'model', 'serial', 'asset_tag',
            'management_address', 'management_port', 'bmc_address', 'bmc_port',
            'operating_system', 'notes',
        ],
        'response' => $one('Server', 201),
    ],
    'api.admin.infrastructure.servers.classify' => [
        'tag' => 'Operator',
        'summary' => 'Change what may be done to a machine',
        'description' => 'Raises one rung at a time and lowers freely — the safe direction needs no ceremony, '
            .'the unsafe one does. Reaching reimage_allowed additionally requires confirm_name to match the '
            .'machine. Lowering the classification drops any existing reimage clearance with it. '
            .'409 if the requested classification is not reachable from the current one.',
        'permission' => 'safety.change',
        'body' => ['safety_class', 'reason', 'confirm_name'],
        'response' => $one('Server'),
    ],
    'api.admin.infrastructure.servers.clear_for_reimage' => [
        'tag' => 'Operator',
        'summary' => 'Clear one machine for reimaging',
        'description' => 'The second of the two decisions, and the destructive one: a machine already classified '
            .'reimage_allowed is signed off for an actual wipe. Requires the machine name typed back. '
            .'409 if the classification does not permit it — the clearance cannot supply its own permission.',
        'permission' => 'allow.reimage',
        'body' => ['confirm_name', 'reason'],
        'response' => $one('Server'),
    ],
    'api.admin.infrastructure.servers.revoke_reimage_clearance' => [
        'tag' => 'Operator',
        'summary' => 'Withdraw a reimage clearance',
        'description' => 'Deliberately cheaper to hold than to grant, and behind the lesser permission: taking a '
            .'destructive clearance away must never be the harder of the two things to do.',
        'permission' => 'safety.change',
        'response' => $one('Server'),
    ],
    'api.admin.infrastructure.servers.connection_test' => [
        'tag' => 'Operator',
        'summary' => 'Try to reach a machine',
        'description' => 'Reading only, and refused outright for a do_not_touch machine — an unclassified machine '
            .'is not probed to find out what it is. A test that times out is recorded as indeterminate, not as a '
            .'failure, and never as a success. 409 when the classification forbids it; 422 when no credential is '
            .'configured to try.',
        'permission' => 'infrastructure.manage',
        'response' => $one('ConnectionTest'),
    ],
    /* ---------------------------------------------------------------------
     | Providers — the accounts Lynomia holds with other people
     |
     | Registering and enabling are separate endpoints rather than one update
     | carrying a state field, so each is one intention with one audit row. A
     | PATCH that could set `state: enabled` alongside other edits would make
     | "who turned on the payment provider" a question about a diff.
     |
     | Nothing here returns a credential value or its location in the secret
     | store, on any endpoint, in any state.
     */

    'api.admin.providers.catalogue' => [
        'tag' => 'Operator',
        'summary' => 'What this build can be pointed at',
        'description' => 'The drivers that exist, with what each needs before it will work. `testable` says '
            .'whether a connection tester exists — several adapters can do real work and cannot yet be proven, '
            .'and a screen that hides that offers a button which cannot succeed.',
        'permission' => 'infrastructure.view',
        'response' => ['envelope' => 'list', 'schema' => 'CatalogueEntry'],
    ],
    'api.admin.providers.index' => [
        'tag' => 'Operator',
        'summary' => 'Provider accounts',
        'description' => 'Ordered blocked first, then draft: the control centre exists to show what is stopping '
            .'the platform selling something.',
        'permission' => 'infrastructure.view',
        'query' => ['category', 'environment', 'state'],
        'response' => $many('Provider'),
    ],
    'api.admin.providers.show' => [
        'tag' => 'Operator',
        'summary' => 'One provider account',
        'permission' => 'infrastructure.view',
        'response' => $one('Provider'),
    ],
    'api.admin.providers.store' => [
        'tag' => 'Operator',
        'summary' => 'Register a provider',
        'description' => 'Declares that a provider is meant to exist. Nothing is contacted. 409 for a driver with '
            .'no adapter, a category that disagrees with the adapter, a missing machine for a provider that runs '
            .'on one, or a machine in another environment.',
        'permission' => 'provider.manage',
        'body' => ['name', 'driver', 'category', 'environment', 'endpoint', 'managed_server_id', 'notes'],
        'response' => $one('Provider', 201),
    ],
    'api.admin.providers.enable' => [
        'tag' => 'Operator',
        'summary' => 'Start routing real work here',
        'description' => 'Readiness is reassessed inside the transaction rather than read from the stored column, '
            .'so a credential revoked since the screen loaded is caught. 409 if anything is still missing, or if '
            .'another provider of the same category is already enabled in this environment — two would be two '
            .'answers to the same question.',
        'permission' => 'provider.manage',
        'response' => $one('Provider'),
    ],
    'api.admin.providers.disable' => [
        'tag' => 'Operator',
        'summary' => 'Stop routing new work here',
        'description' => 'Stops new work reaching this provider and touches nothing it already serves: existing '
            .'services keep running, keep being backed up and keep being billed. A reason is required.',
        'permission' => 'provider.manage',
        'body' => ['reason'],
        'response' => $one('Provider'),
    ],
    'api.admin.providers.assess' => [
        'tag' => 'Operator',
        'summary' => 'Recompute what is blocking a provider',
        'description' => 'Contacts nobody. For the case an edit does not cover — a licence that lapsed by the '
            .'calendar rather than by somebody changing it.',
        'permission' => 'provider.manage',
        'response' => $one('Provider'),
    ],
    'api.admin.providers.connection_test' => [
        'tag' => 'Operator',
        'summary' => 'Ask a provider whether it answers, and what it can do',
        'description' => 'One round trip that proves the credential and discovers the account\'s capabilities, '
            .'then recomputes readiness. A timeout is recorded as indeterminate, never as a failure and never as '
            .'a success. 422 when no connection tester exists for the driver, which is the common case in this '
            .'build; 409 when the machine it runs on is classified against being touched.',
        'permission' => 'provider.manage',
        'response' => $one('ConnectionTest'),
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
