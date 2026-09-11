/**
 * The shapes the API actually returns.
 *
 * Hand-written, and that is a deliberate temporary state rather than a
 * preference: the specification these should be generated from does not exist
 * yet (see docs/api.md). Until it does, every interface here is a claim about
 * the server that only a running test can check, so the ones that matter are
 * asserted in tests rather than trusted.
 */

/** Money, exactly as every endpoint serialises it. Never a number. */
export interface Money {
  minor_units: number
  currency: string
  amount: string
}

/** The envelope every collection endpoint returns. */
export interface Paginated<T> {
  data: T[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    max_per_page: number
    [key: string]: unknown
  }
}

export interface Envelope<T> {
  data: T
}

export interface PlanPrice {
  billing_period: 'hourly' | 'daily' | 'monthly' | 'quarterly' | 'yearly'
  currency: string
  recurring: Money
  setup: Money
}

export interface Plan {
  id: string
  product_id: string
  slug: string
  name: string
  description: string | null
  resources: Record<string, unknown>
  stock_limit: number | null
  per_customer_limit: number | null
  prices: PlanPrice[]
}

export interface Product {
  id: string
  kind: 'vps' | 'dedicated' | 'shared_hosting'
  slug: string
  name: string
  description: string | null
  plan_count: number
  plans?: Plan[]
}

export interface OrderItem {
  id: string
  description: string
  quantity: number
  total: Money
}

/** A service an order brought into being, once the order was paid. */
export interface OrderService {
  id: string
  kind: string
  identity: string | null
  /**
   * The row that fulfils this service, as the API resolved it.
   *
   * A service's own id is not the machine's, the hosting account's or the
   * chassis's, so a link built from the service id would point at nothing.
   * The kind is a customer-facing family and the portal owns the route it maps
   * to — the same handle shape a notification carries, so one map turns either
   * into a destination. Null until something has been created.
   */
  resource: { kind: string; id: string } | null
  state: string
  is_usable: boolean
}

export interface Order {
  id: string
  number: string
  status: string
  currency: string
  subtotal: Money
  discount: Money
  tax: Money
  total: Money
  is_paid: boolean
  is_cancellable: boolean
  coupon_code: string | null
  items_count?: number
  items?: OrderItem[]
  /*
   * The chain, from the server: the invoice this order produced and the
   * services that exist because it was paid. Present when one order is read
   * rather than a page of them, so both are optional.
   */
  invoice_id?: string | null
  invoice_number?: string | null
  services?: OrderService[]
  placed_at: string | null
  paid_at: string | null
  cancelled_at: string | null
}

export interface Invoice {
  id: string
  number: string
  status: string
  currency: string
  subtotal: Money
  discount: Money
  tax: Money
  total: Money
  amount_paid: Money
  amount_due: Money
  amount_refunded?: Money
  is_payable: boolean
  is_settled: boolean
  order_id: string | null
  subscription_id?: string | null
  items_count?: number
  /*
   * Present when one invoice is read as a document, absent from a list of
   * them: a list has no use for twelve addresses and twelve line tables.
   */
  items?: InvoiceItem[]
  billing_snapshot?: BillingSnapshot
  payments?: InvoicePaymentRow[]
  wallet_credits?: InvoiceWalletCreditRow[]
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  voided_at?: string | null
}

/**
 * One line of an invoice, as the invoice recorded it.
 *
 * Snapshotted at issue, so a catalogue rename does not rewrite a document
 * somebody has already printed. `tax_rate` is a decimal string because it is
 * the rate the invoice carries, not a number to recompute tax with.
 */
export interface InvoiceItem {
  id: string
  kind: string
  description: string
  quantity: number
  unit_amount: Money
  discount: Money
  tax: Money
  total: Money
  tax_rate: string
  tax_name: string | null
  period_start: string | null
  period_end: string | null
}

/**
 * The billing details an invoice was issued against.
 *
 * Frozen: this is not the customer's profile today. Every field is optional
 * because an invoice issued before a field existed does not carry it, and the
 * screen omits a line rather than inventing one.
 */
export interface BillingSnapshot {
  type?: string
  display_name?: string
  legal_name?: string | null
  registration_number?: string | null
  tax_id?: string | null
  tax_exempt?: boolean
  billing_email?: string | null
  billing_phone?: string | null
  address?: {
    line1?: string | null
    line2?: string | null
    city?: string | null
    state?: string | null
    postal_code?: string | null
    country?: string | null
  }
  captured_at?: string | null
}

/**
 * A payment as an invoice document reports it: how it was paid, whether it
 * worked, and why not when it did not. Billing's own view of a payment rather
 * than the payments surface's, which is why it is a separate type.
 */
export interface InvoicePaymentRow {
  id: string
  /** True when the account paid itself out of its own credit. */
  from_account_credit: boolean
  kind: string
  status: string
  is_settled: boolean
  amount: Money
  failure_code: string | null
  processed_at: string | null
  created_at: string | null
}

/** A wallet ledger entry as an invoice document reports it. */
export interface InvoiceWalletCreditRow {
  id: string
  kind: string
  /** Signed: negative when credit was spent. */
  amount: Money
  direction: 'credit' | 'debit'
  balance_after: Money
  description: string
  created_at: string | null
}

/** One entry in the wallet ledger, which is the only source of a balance. */
export interface WalletTransaction {
  id: string
  kind: string
  /** Signed: negative when the balance went down. */
  amount: Money
  direction: 'credit' | 'debit'
  balance_after: Money
  description: string
  invoice_id: string | null
  transaction_id: string | null
  created_at: string | null
}

export interface Subscription {
  id: string
  status: string
  currency: string
  billing_period: string
  recurring_amount: Money
  plan_id: string | null
  /**
   * What this agreement is for. Present when the API was asked for
   * subscriptions rather than for one subscription's summary, and absent
   * otherwise — hence optional rather than nullable.
   */
  plan?: { id: string; name: string; billing_period: string } | null
  product?: { id: string; kind: string; name: string } | null
  services?: SubscriptionService[]
  current_period_end: string | null
  next_invoice_at: string | null
  auto_renew: boolean
  is_scheduled_to_cancel: boolean
  service_is_running: boolean
  /** How long the data behind this subscription's services is kept once it ends. */
  data_retention_days: number | null
}

/**
 * What moving to one plan would cost, as the backend computed it.
 *
 * Every money field is a Money — minor units and a currency — and the portal
 * renders them and computes nothing. Proration, rounding and currency
 * behaviour are the platform's rules; a second implementation of them here
 * would agree with the backend until it did not, and the first customer to
 * notice would be one charged something other than what this screen said.
 */
export interface PlanChangeQuote {
  plan_id: string
  price_id: string
  plan_name: string
  is_available: boolean
  /** Stable keys; this portal owns the wording, in both languages. */
  refusals: string[]
  warnings: string[]
  current_recurring: Money
  new_recurring: Money
  credit: Money | null
  charge: Money | null
  amount_due_now: Money | null
  effective_at: string
  period_end: string
  current_resources: { vcpu: number | null; memory_mib: number | null; disk_gib: number | null }
  new_resources: { vcpu: number | null; memory_mib: number | null; disk_gib: number | null }
  changes_infrastructure: boolean
}

/**
 * A service a subscription pays for, named the way the customer names it.
 *
 * `identity` is the hostname, domain or serial; it is null while the service is
 * still being created, and a screen says so rather than showing a placeholder
 * that looks like a name.
 */
export interface SubscriptionService {
  id: string
  kind: string
  label: string | null
  identity: string | null
  /**
   * The row that fulfils this service, as the API resolved it.
   *
   * A service's own id is not the machine's, the hosting account's or the
   * chassis's, so a link built from the service id would point at nothing.
   * The kind is a customer-facing family and the portal owns the route it maps
   * to — the same handle shape a notification carries, so one map turns either
   * into a destination. Null until something has been created.
   */
  resource: { kind: string; id: string } | null
  state: string
  is_usable: boolean
}

export interface Payment {
  id: string
  kind: string
  status: string
  amount: Money
  invoice_id: string | null
  /**
   * True when the account paid itself out of its own credit. It replaced the
   * gateway's registered driver name, which named a driver rather than
   * anything the payer did — and which this portal rendered as a raw slug
   * whenever its translation namespace did not cover it.
   */
  from_account_credit: boolean
  is_settled: boolean
  failure_code: string | null
  processed_at: string | null
}

export interface Service {
  id: string
  kind: 'vps' | 'dedicated' | 'shared_hosting'
  label: string | null
  /**
   * The name the customer knows this by — a hostname, a primary domain, a
   * serial. The label beside it comes from the catalogue and is the same
   * string for everyone who bought the plan, which is why two servers on one
   * plan used to be two identical rows. Null while the service is being
   * created.
   */
  identity: string | null
  /**
   * The row that fulfils this service, as the API resolved it.
   *
   * A service's own id is not the machine's, the hosting account's or the
   * chassis's, so a link built from the service id would point at nothing.
   * The kind is a customer-facing family and the portal owns the route it maps
   * to — the same handle shape a notification carries, so one map turns either
   * into a destination. Null until something has been created.
   */
  resource: { kind: string; id: string } | null
  state: string
  is_usable: boolean
  resources: Record<string, unknown>
  /**
   * What bought this, as ids the server published.
   *
   * The commercial half of a service: the catalogue plan, the order line it
   * came from, and the subscription that renews it. Declared here in Wave 3
   * because a resource page shows the billing relationship, and the audit's
   * finding was that the customer had to infer the whole chain — matching it
   * in the client on amount or date would have been the same defect with
   * better manners.
   */
  plan_id: string | null
  order_id: string | null
  subscription_id: string | null
  activated_at: string | null
  suspended_at: string | null
  /**
   * When the data behind a stopped service is destroyed, and why it stopped.
   * Both null while it is running.
   */
  retention_ends_at: string | null
  ended_reason: string | null
  terminated_at: string | null
  created_at: string | null
}

/**
 * Whether the API would accept each disruptive control right now, and why
 * not when it would not. Published by the server from the same facts its
 * operation guard refuses on, so a screen reading this never enables a button
 * the endpoint already knows it will answer 409 to. The guard stays the
 * authority; this is what the screen says.
 */
export interface ActionAvailability {
  power: boolean
  reinstall: boolean
  blocked_reason: string | null
}

export interface VirtualMachine {
  id: string
  service_id: string | null
  hostname: string
  service_status: string
  power_state: string
  /**
   * Nested, as the API sends it. The row used to declare these at the top
   * level and read `vm.memory_mib`, which rendered "NaN GiB" on every machine.
   */
  resources: {
    vcpu: number
    memory_mib: number
    disk_gib: number
  }
  os_family: string | null
  os_version: string | null
  addresses: Array<{ address: string; ip_version: number; is_primary: boolean }>
  is_operable: boolean
  actions: ActionAvailability
  /**
   * The machine's most recent rebuild, or null if it has never had one.
   *
   * `data_destroyed` is the field to read before saying anything reassuring:
   * it is true from the moment the disk replacement was attempted, including
   * when the platform does not know whether it finished.
   */
  reinstall: {
    id: string
    state: string
    in_flight: boolean
    needs_attention: boolean
    data_destroyed: boolean
    requested_at: string
    completed_at: string | null
  } | null
}

export interface AppNotification {
  id: string
  type: string
  category: string
  /** Rendered by the API in the reader's language, not assembled here. */
  title: string
  body: string
  is_failure: boolean
  /** A collection path. The resource below is the precise destination. */
  link: string | null
  /**
   * What this notification is about, resolved by the API from the stored
   * subject — never guessed from the text. The portal owns the route each
   * kind maps to.
   */
  resource: { kind: string; id: string } | null
  read_at: string | null
  created_at: string
}

export interface NotificationPreference {
  category: string
  channel: string
  enabled: boolean
  /** False where the platform will not honour a change. */
  changeable: boolean
}

export interface Backup {
  id: string
  service_id: string | null
  state: string
  trigger: string
  mode: string
  /** The three questions a customer actually has about a backup. */
  is_in_flight: boolean
  is_restorable: boolean
  needs_attention: boolean
  size_bytes: number | null
  verified: boolean | null
  verified_at: string | null
  retention_days: number | null
  expires_at: string | null
  /**
   * Where a deletion has got to. `is_being_deleted` is true from the moment
   * one is asked for; `deleted_at` is stamped only when the datastore no
   * longer lists the archive. Showing "deleted" for one still on a datastore
   * would be the same false claim as showing "available" for one that is gone.
   */
  is_being_deleted: boolean
  deletion_requested_at: string | null
  deleted_at: string | null
  protected_until: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  failure_reason: string | null
  /** Whether this backup can be opened file by file, and if not, why not. */
  files: { supported: boolean; reason: string | null }
}

export interface CountryCurrencyImpact {
  /** Counts and minor-unit amounts in the currency named beside them. Nothing is converted. */
  facts: Record<string, string | number | boolean>
  /** What must change before this can be applied, in words. */
  blockers: string[]
  /** What will be true afterwards; not a reason to refuse. */
  warnings: string[]
}

export interface CountryCurrencyChange {
  id: string
  customer_id: string
  state: string
  is_open: boolean
  needs_attention: boolean
  from_country: string | null
  to_country: string | null
  from_currency: string
  to_currency: string
  reason: string
  impact: CountryCurrencyImpact
  decision_note: string | null
  analysed_at: string
  decided_at: string | null
  scheduled_for: string | null
  applied_at: string | null
  created_at: string
}

export type BackupFileKind = 'file' | 'directory' | 'symlink' | 'other'

export interface BackupFileEntry {
  /** Absolute inside the archive. Nothing about where the archive lives. */
  path: string
  name: string
  kind: BackupFileKind
  size_bytes: number | null
  modified_at: string | null
  downloadable: boolean
  browsable: boolean
  /** False for a symlink, always: links are never followed out of a backup. */
  restorable: boolean
}

export interface BackupFileListing {
  path: string
  parent: string | null
  truncated: boolean
  entries: BackupFileEntry[]
}

export interface BackupFileDownload {
  id: string
  path: string
  expires_at: string
  /** Relative to the API origin; carries the single-use token. */
  url: string
}

export interface BackupFileRestore {
  id: string
  backup_id: string
  state: string
  is_in_flight: boolean
  needs_attention: boolean
  paths: string[]
  path_count: number
  started_at: string | null
  finished_at: string | null
  created_at: string
  failure_reason: string | null
}

export interface DedicatedServer {
  id: string
  serial: string
  manufacturer: string
  model: string
  status: string
  power_state: string
  is_powered_on: boolean
  actions: ActionAvailability
  service_id: string | null
  activated_at?: string | null
  /**
   * The machine's most recent rebuild, or null if it has never had one.
   *
   * `data_destroyed` is true from the moment the machine was told to boot into
   * an installer — including when the platform never heard back — so it is the
   * field to read before saying anything reassuring about a failed rebuild.
   */
  reinstall: {
    id: string
    state: string
    in_flight: boolean
    needs_attention: boolean
    data_destroyed: boolean
    requested_at: string
    completed_at: string | null
  } | null
}

/**
 * What the platform sold this account, as the API sends it.
 *
 * An object, not a name. The portal declared it a string, so every account
 * with a package behind it rendered an object as a React child and took the
 * whole hosting table down — which nothing noticed, because no fixture in the
 * browser suite had an account on it and the seeded catalogue had no packages
 * at all.
 */
export interface HostingPackage {
  slug: string
  /** The catalogue plan, in the reader's language. The slug is a join key. */
  plan_name: string | null
  disk_quota_mib: number | null
  bandwidth_quota_mib: number | null
  max_addon_domains: number | null
  max_subdomains: number | null
  max_databases: number | null
  max_email_accounts: number | null
}

export interface HostingAccount {
  id: string
  service_id: string | null
  username: string
  primary_domain: string | null
  status: string
  package: HostingPackage | null
  /**
   * `cpanel` or `directadmin`: which panel the customer is about to sign
   * into. Null where the platform will not name it — no node yet, or a
   * controlled fake — and the screen then says "hosting control panel",
   * which is true in every case.
   */
  panel_type: string | null
  disk_quota_mib: number | null
  bandwidth_quota_mib: number | null
}

/**
 * A reading of what an account is using, and when it was taken.
 *
 * Never a live figure: the panel is asked on its own schedule, so the
 * timestamp is part of the fact. `null` measures mean the platform has not
 * been told, which is not the same as zero.
 */
export interface HostingUsage {
  account_id: string
  username: string
  disk: HostingUsageMeasure
  bandwidth: HostingUsageMeasure
  measured_at: string | null
}

export interface HostingUsageMeasure {
  used_mib: number | null
  quota_mib: number | null
  /**
   * True for a plan sold without a ceiling, false for one with a quota, and
   * null when there is no package to read at all — which is not the same as
   * unlimited. The API is explicit about the difference because "unlimited"
   * beside a figure the platform is about to enforce a quota against is the
   * same class of lie as drawing an unmeasured account at 0%.
   */
  unlimited: boolean | null
  used_percent: number | null
}

/** The freshness contract that comes with a usage reading. */
export interface HostingUsageMeta {
  measured_at: string | null
  age_seconds: number | null
  never_measured: boolean
  stale: boolean
  stale_after_seconds: number
  source: string
}

/**
 * An operating system a machine may be rebuilt with.
 *
 * The list comes from the platform's own staged images for that machine, not
 * from a list of distributions written into the portal: a frontend that
 * offered Ubuntu because Ubuntu is popular would be offering a rebuild the
 * API refuses.
 */
export interface InstallableTemplate {
  id: string
  name: string
  os_family: string
  os_version: string
  architecture: string
  /** Whether a public key can be installed on first boot. */
  supports_ssh_keys: boolean
  requires_licence: boolean
}

export interface WalletBalance {
  // Null until the customer has actually transacted in this currency: the API
  // reports the account currency whether or not a wallets row was ever opened,
  // so a zero balance here is a real answer, not a missing one.
  currency: string
  balance: Money
  updated_at: string | null
}

/**
 * GET /wallet answers a list, one balance per currency, and never a total —
 * summing two currencies would need a rate and the platform honours none. The
 * envelope is spelled out here rather than reusing Envelope<T> so that the
 * portal cannot go back to reading `.balance` off the collection.
 */
export interface WalletBalances {
  data: WalletBalance[]
  meta: {
    account_currency: string
    currencies: number
  }
}

export interface IpAssignment {
  id: string
  ip_address: string
  ip_version: number
  is_primary: boolean
  service_id: string | null
  assigned_at: string | null
  reverse_dns: { hostname: string; status: string } | null
}

export type TicketStatus =
  | 'open'
  | 'waiting_for_support'
  | 'waiting_for_customer'
  | 'resolved'
  | 'closed'

export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent'

export interface TicketAttachment {
  id: string
  name: string
  mime_type: string
  size_bytes: number
}

export interface TicketMessage {
  id: string
  author: string | null
  author_kind: 'customer' | 'operator' | 'system'
  body: string
  is_internal_note: boolean
  attachments: TicketAttachment[]
  created_at: string
}

export interface Ticket {
  id: string
  reference: string
  subject: string
  category: string
  status: TicketStatus
  priority: TicketPriority
  service_id: string | null
  invoice_id: string | null
  opened_by: string | null
  last_reply_at: string | null
  last_reply_by: 'customer' | 'operator' | 'system' | null
  awaiting_customer: boolean
  resolved_at: string | null
  closed_at: string | null
  created_at: string
  messages: TicketMessage[]
}

/**
 * What paying one invoice from stored credit would do.
 *
 * Three figures rather than one: a client that subtracted them itself would be
 * re-implementing the server's rule that a wallet cannot overpay an invoice.
 */
export interface WalletCreditQuote {
  available: Money
  applicable: Money
  remaining: Money
  is_payable: boolean
}

/**
 * A role inside one customer account. Distinct from the platform roles an
 * operator holds: this says what a person may do inside one customer.
 */
export type TeamRole = 'owner' | 'administrator' | 'billing' | 'technical' | 'member'

export interface TeamMember {
  id: string
  name: string | null
  email: string | null
  role: TeamRole
  invited_by: string | null
  invited_at: string | null
  joined_at: string | null
}

export type InvitationStatus = 'pending' | 'accepted' | 'declined' | 'revoked' | 'expired'

/**
 * An outstanding offer. There is no token field here and there must never be
 * one: the server stores a hash and the only copy of a token is in the mail.
 */
/**
 * One capability a role either has or does not, as the server publishes it.
 *
 * `permission` is the canonical machine value — the string the API refuses
 * with — and `id` is what the portal translates. Both travel so that the
 * explanation on the screen can be checked against a real 403 rather than
 * taken on trust.
 */
export interface TeamCapability {
  id: string
  permission: string
  granted: boolean
}

/**
 * A team role and the whole capability matrix for it.
 *
 * Computed on the server from the same permission list `AuthorisesWithinAccount`
 * reads before every write, so the table a customer reads and the endpoint that
 * refuses them cannot disagree. An architecture test asserts the published set
 * and the enforced set are equal in both directions.
 */
export interface TeamRoleCapabilities {
  id: TeamRole
  is_owner: boolean
  assignable: boolean
  capabilities: TeamCapability[]
}

export interface TeamInvitation {
  id: string
  email: string
  role: TeamRole
  status: InvitationStatus
  invited_by: string | null
  invited_at: string
  expires_at: string
  sent_count: number
  last_sent_at: string | null
}

/** What the invitee is shown about an offer they hold the token for. */
export interface InvitationOffer {
  account: string | null
  role: TeamRole
  invited_by: string | null
  expires_at: string
  is_for_you: boolean
}

export interface ApiToken {
  id: string
  name: string
  status: string
  /**
   * Always `["*"]` today, and checked by nothing.
   *
   * Typed because the API publishes it, and deliberately not rendered: a
   * screen that printed "full access" from this field would be describing a
   * restriction the platform does not enforce.
   */
  abilities: string[]
  /** Null means "from anywhere". Enforced at authentication. */
  allowed_ip_ranges: string[] | null
  /** Null means the tier default. Replaces it in the API limiter. */
  rate_limit_per_minute: number | null
  last_used_at: string | null
  last_used_ip: string | null
  expires_at: string | null
  revoked_at: string | null
  created_at: string | null
}

/* --------------------------------------------------------------------------
 | DNS
 |
 | `nameservers` is the whole product on a zone: until the domain is delegated
 | to them at the registrar, the zone serves nobody. Nothing here is called
 | "verified", because nothing verifies anything — see docs/dns.md.
 */

export type DnsRecordType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'CAA'

/**
 * One namespace's answer for one name.
 *
 * `availability` has five values and the screen must render all five. Folding
 * `unknown` into available sells a name that is taken; folding it into
 * unavailable turns away a customer who could have had it.
 */
export interface DomainSearchResult {
  name: string
  tld: string
  availability: 'available' | 'unavailable' | 'premium' | 'unknown' | 'unsupported'
  is_orderable: boolean
  premium: boolean
  currency: string | null
  /** Null whenever the platform will not commit to a number. A zero would read as free. */
  price_minor: number | null
  term_years: number
}

/** A price the platform will honour, referred to afterwards by id alone. */
export interface DomainQuote {
  id: string
  name: string
  tld: string
  operation: string
  term_years: number
  premium: boolean
  currency: string
  price_minor: number
  expires_at: string
}

export interface Domain {
  id: string
  name: string
  tld: string
  state: string
  is_held: boolean
  is_manageable: boolean
  is_renewable: boolean
  is_redeemable: boolean
  needs_attention: boolean
  redemption: DomainRedemption | null
  term_years: number
  auto_renew: boolean
  transfer_locked: boolean | null
  nameservers: string[]
  dns_zone_id: string | null
  registered_at: string | null
  expires_at: string | null
  is_expiring: boolean
  created_at: string
}

/**
 * Whether a lapsed name can be recovered here, why not if not, the
 * catalogue price, and where the last attempt stands. `unknown` is the .sy
 * answer: no published policy, and the platform will not invent one.
 */
export interface DomainRedemption {
  support: 'supported' | 'unsupported' | 'unknown' | 'blocked_configuration'
  reason: string
  currency: string | null
  price_minor: number | null
  attempt: {
    id: string
    state: string
    invoice_id: string | null
    needs_attention: boolean
    completed_at: string | null
  } | null
}

export interface DomainOperation {
  id: string
  domain_id: string | null
  name: string
  kind: string
  state: string
  term_years: number
  currency: string
  price_minor: number
  invoice_id: string | null
  is_in_flight: boolean
  needs_attention: boolean
  completed_at: string | null
  created_at: string
}

/**
 * A WordPress site: an account, a name, a certificate and an installation.
 *
 * `is_verified` is the only field here that is this platform's own
 * observation. Everything else is somebody else's report — the panel's, the
 * installer's, the certificate authority's — and all three can be true while
 * the site serves a database error.
 */
export interface WordPressSite {
  id: string
  domain: string
  domain_source: 'register' | 'existing' | 'transfer' | 'external'
  domain_id: string | null
  state: string
  is_usable: boolean
  is_verified: boolean
  needs_attention: boolean
  dns_ready: boolean
  installed: boolean
  ssl_status: string
  site_url: string | null
  admin_url: string | null
  admin_username: string | null
  wordpress_version: string | null
  locale: string | null
  failure_reason: string | null
  hosting_account_id: string | null
  verified_at: string | null
  created_at: string
  /** A staging copy belongs to its parent and is the only kind that can be pushed back. */
  kind: 'production' | 'staging' | 'clone'
  parent_site_id: string | null
  /** What this site's panel toolkit can do with it right now, and the reason when nothing. */
  copies: { staging: boolean; clone: boolean; push_to_production: boolean; reason: string | null }
}

export interface WordPressPushImpact {
  staging_domain: string
  production_domain: string
  scope: string
  copy_made_at: string
  production_verified_at: string | null
  /** Always null: this platform holds no backup of a shared-hosting site. */
  platform_backup: null
  warnings: string[]
}

export interface WordPressSiteOperation {
  id: string
  site_id: string
  target_site_id: string | null
  kind: 'create_staging' | 'clone' | 'push_to_production'
  state: string
  is_in_flight: boolean
  needs_attention: boolean
  scope: string | null
  impact: WordPressPushImpact | null
  failure_reason: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

/**
 * The registrant on record for a name.
 *
 * The customer's own personal data, read back so a correction does not have
 * to be retyped from memory. Exactly the fields the update accepts.
 */
export interface DomainContact {
  role: string
  name: string
  organisation: string | null
  email: string
  phone: string
  address_line_one: string
  address_line_two: string | null
  city: string
  region: string | null
  postal_code: string | null
  country: string
  updated_at: string
}

/**
 * One thing that happened to one service, in customer-safe terms.
 *
 * The API maps the provisioning job's own state and failure class onto a
 * customer vocabulary and withholds the attempt log, the provider and the
 * internal error — see the events endpoint. There is no account-wide feed:
 * that is a later wave's work, and inventing one here would mean inventing
 * an endpoint.
 */
export interface ServiceEvent {
  id: string
  kind: string
  state: CustomerOperationState
  is_terminal: boolean
  needs_attention: boolean
  retry_advice: RetryAdvice
  failure_reason: string | null
  created_at: string
  started_at: string | null
  finished_at: string | null
}

/**
 * The seven words the API uses for a piece of asynchronous work, and the only
 * ones a screen ever sees.
 *
 * Written as a union rather than as `string` because the two that matter are
 * the two a component would otherwise be free to lump in with `failed`:
 *
 *  - `needs_review` means it stopped and a person at Lynomia has to look. A
 *    customer told it failed will press the button again, on a machine that
 *    may be half-built.
 *  - `indeterminate` means the platform asked something outside itself and
 *    never heard back. The result is genuinely unknown, and presenting either
 *    guess as fact is how a name gets bought twice.
 *
 * A `switch` over this union with a missing arm is a type error, which is the
 * point: the compiler is the thing that stops the collapse.
 */
export type CustomerOperationState =
  | 'queued'
  | 'processing'
  | 'succeeded'
  | 'failed'
  | 'needs_review'
  | 'indeterminate'
  | 'cancelled'

/**
 * What the customer may safely do next, decided by the server.
 *
 * A retry control is drawn from `safe_to_retry` and from nothing else. The
 * portal never derives this from the state, because the derivation is exactly
 * where "failed, so offer a retry" creeps back in and takes `indeterminate`
 * with it.
 */
export type RetryAdvice = 'safe_to_retry' | 'wait' | 'support_required' | 'not_retryable'

/**
 * One piece of work in flight, as `GET /operations/{id}` reports it.
 *
 * `poll_after_ms` is the server's hint about when to read again and is null
 * once the state is terminal, which is what makes "polling stops when the work
 * stops" a property of the contract rather than a promise about a component.
 */
export interface CustomerOperation {
  id: string
  kind: string | null
  /** The customer's own verb where there is one — `reboot`, not `restart`. */
  action: string | null
  state: CustomerOperationState
  is_terminal: boolean
  needs_attention: boolean
  retry_advice: RetryAdvice
  failure_reason: string | null
  resource: { service_id: string } | null
  requested_at: string | null
  started_at: string | null
  updated_at: string | null
  finished_at: string | null
  poll_after_ms: number | null
}

/** The 202 receipt a power action or a rebuild returns. */
export interface AcceptedOperation {
  id: string
  service_id: string | null
  kind: string | null
  action: string | null
  state: CustomerOperationState
  is_terminal: boolean
  needs_attention: boolean
  retry_advice: RetryAdvice
  requested_at: string | null
  finished_at: string | null
}

/**
 * The receipt a dedicated-server rebuild returns.
 *
 * Shorter than the VPS one because there is less to say: a physical rebuild
 * has no `action` verb to echo and its service is the machine the request was
 * addressed to. The state vocabulary is the same one, from the same mapping.
 */
export interface AcceptedReinstall {
  id: string
  state: CustomerOperationState
  is_terminal: boolean
  needs_attention: boolean
  retry_advice: RetryAdvice
  requested_at: string | null
  started_at: string | null
  finished_at: string | null
}

/** A handle the portal turns into an address through one function. */
export interface ResourceHandle {
  kind: string
  id: string
  identity: string | null
}

/**
 * One row of the account-wide feed.
 *
 * `message_code` is a translation key, not a sentence: the server says what
 * happened and the portal says it in the reader's language. The actor is typed
 * — `customer_user`, `system` or `unknown` — and `unknown` is the honest answer
 * where the source row records no requester rather than a guess at whoever was
 * signed in at the time.
 */
export interface ActivityItem {
  id: string
  occurred_at: string
  category: ActivityCategory
  message_code: string
  state: CustomerOperationState
  is_terminal: boolean
  needs_attention: boolean
  retry_advice: RetryAdvice
  actor: { type: 'customer_user' | 'system' | 'unknown'; display_name: string | null }
  resource: ResourceHandle | null
  reference: string | null
}

export type ActivityCategory = 'cloud' | 'hosting' | 'domains' | 'billing' | 'support' | 'backups'

/** The cursor page the feed returns. Opaque cursors, walked forwards only. */
export interface ActivityPage {
  data: ActivityItem[]
  meta: { per_page: number; next_cursor: string | null; has_more: boolean }
}

/** One thing the account should look at, in the server's priority order. */
export interface AttentionItem {
  id: string
  kind: string
  severity: 'critical' | 'warning' | 'info'
  occurred_at: string
  resource: ResourceHandle | null
  reference: string | null
}

/**
 * The dashboard, in one read.
 *
 * `billing.due` is one entry per currency and there is deliberately no total:
 * an account billed in two currencies has two amounts owing, and a single
 * number would be arithmetic nobody can perform.
 */
export interface AccountOverview {
  attention: AttentionItem[]
  services: { total: number; by_state: Record<string, number> }
  billing: { due: Array<{ invoices: number; amount: Money }> }
  renewals: Array<{
    kind: string
    resource: { kind: string | null; id: string; identity: string | null }
    at: string
    amount: Money | null
  }>
  unread_notifications: number
  recent: {
    services: Array<{ kind: string; id: string; identity: string | null; state: string }>
    activity: ActivityItem[]
  }
}

export interface DnsZone {
  id: string
  name: string
  service_id: string | null
  state: string
  is_live: boolean
  is_being_deleted: boolean
  needs_attention: boolean
  nameservers: string[]
  failure_reason: string | null
  record_count?: number
  last_synced_at: string | null
  created_at: string
}

export type ZoneChangeKind = 'add' | 'update' | 'remove' | 'unchanged' | 'refused' | 'ignored'
export type ZoneImportMode = 'merge' | 'replace'

export interface ZoneImportEntry {
  kind: ZoneChangeKind
  line: number | null
  type: DnsRecordType | null
  name: string | null
  /** The value, or for a refused or ignored line the line's text. */
  content: string | null
  ttl: number | null
  priority: number | null
  existing_id: string | null
  reason: string | null
}

export interface ZoneImportPlan {
  zone_id: string
  zone: string
  mode: ZoneImportMode
  /** False while any entry is refused: nothing is applied then. */
  applicable: boolean
  /** Sent back on apply; a zone that changed since the preview makes it stale. */
  fingerprint: string
  counts: Record<'add' | 'update' | 'remove' | 'unchanged' | 'refused' | 'ignored' | 'kept', number>
  entries: ZoneImportEntry[]
}

export interface ZoneImportResult {
  zone_id: string
  mode: ZoneImportMode
  added: number
  updated: number
  removed: number
  unchanged: number
}

export interface ZoneExport {
  filename: string
  content: string
  record_count: number
}

export interface DnsRecord {
  id: string
  zone_id: string
  type: DnsRecordType
  name: string
  content: string
  ttl: number
  priority: number | null
  caa_flags: number | null
  caa_tag: string | null
  caa_value: string | null
  state: string
  is_live: boolean
  is_being_deleted: boolean
  needs_attention: boolean
  failure_reason: string | null
  last_published_at: string | null
  created_at: string
}

/**
 * What a basket costs, priced by the server.
 *
 * The portal renders these figures and adds up none of them. The same engine
 * priced this quote and will price the order, and a contract test in the
 * backend compares the two — so a screen that recomputed a total here could
 * only ever be wrong in a way the customer would notice on their invoice.
 */
export interface OrderQuoteLine {
  description: string
  plan_id: string
  quantity: number
  unit_recurring: Money
  unit_setup: Money
  gross: Money
  discount: Money
  tax: Money
  total: Money
  tax_rate: string
  tax_name: string | null
}

export interface OrderQuote {
  currency: string
  billing_period: string
  lines: OrderQuoteLine[]
  setup: Money
  subtotal: Money
  discount: Money
  tax: Money
  total: Money
  coupon_code: string | null
  tax_rate: string
  tax_name: string | null
  renewal: {
    billing_period: string
    subtotal: Money
    tax: Money
    total: Money
    includes_setup: boolean
    includes_coupon: boolean
  }
}

/**
 * The countries and currencies registration may offer, from the server.
 *
 * Country names are absent on purpose: the browser has CLDR's translations for
 * every locale this portal speaks, and a half-translated table shipped in the
 * bundle would be worse than none. `currency_is_explicit` is false when the
 * country has no row of its own and takes the platform's stated fallback,
 * which the screen says out loud.
 */
export interface RegistrationCountry {
  code: string
  currency: string
  currency_is_explicit: boolean
}

export interface RegistrationOptions {
  countries: RegistrationCountry[]
  currencies: string[]
  fallback_currency: string
}

/**
 * What the platform needs the browser to do about a payment it has opened.
 *
 * Five answers, and each one is a different screen. `completed` is the
 * provider saying it already has the money, which is still not the same as the
 * invoice being settled — that is decided by the webhook and read back from
 * the invoice. `pending` is the one state in which a client must not start a
 * second payment.
 */
export type PaymentNextActionType =
  | 'redirect'
  | 'client_confirmation'
  | 'completed'
  | 'failed'
  | 'pending'

export interface StartedPayment {
  payment: Payment
  provider: string
  reference: string
  status: string
  next_action: {
    type: PaymentNextActionType
    redirect_url: string | null
    client_secret: string | null
    is_awaiting_provider: boolean
  }
  failure_code: string | null
  failure_message: string | null
}
