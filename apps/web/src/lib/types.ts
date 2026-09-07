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
  items_count: number
  items?: OrderItem[]
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
  is_payable: boolean
  is_settled: boolean
  order_id: string | null
  items_count: number
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
}

export interface Subscription {
  id: string
  status: string
  currency: string
  billing_period: string
  recurring_amount: Money
  plan_id: string | null
  current_period_end: string | null
  next_invoice_at: string | null
  auto_renew: boolean
  is_scheduled_to_cancel: boolean
  service_is_running: boolean
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

export interface Payment {
  id: string
  kind: string
  status: string
  amount: Money
  invoice_id: string | null
  provider: string
  is_settled: boolean
  failure_code: string | null
  failure_message: string | null
  processed_at: string | null
}

export interface Service {
  id: string
  kind: 'vps' | 'dedicated' | 'shared_hosting'
  label: string | null
  state: string
  is_usable: boolean
  resources: Record<string, unknown>
  activated_at: string | null
}

export interface VirtualMachine {
  id: string
  service_id: string | null
  hostname: string
  service_status: string
  power_state: string
  vcpu: number
  memory_mib: number
  disk_gib: number
  os_family: string | null
  os_version: string | null
  addresses: Array<{ address: string; ip_version: number; is_primary: boolean }>
  is_operable: boolean
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
  link: string | null
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
  hardware_profile: string
  status: string
  power_state: string
  is_powered_on: boolean
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

export interface HostingAccount {
  id: string
  service_id: string | null
  username: string
  primary_domain: string | null
  status: string
  package: string | null
  disk_quota_mib: number | null
  bandwidth_quota_mib: number | null
}

export interface WalletBalance {
  // Null until the customer has actually transacted in this currency: the API
  // reports the account currency whether or not a wallets row was ever opened,
  // so a zero balance here is a real answer, not a missing one.
  wallet_id: string | null
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

export interface ApiToken {
  id: string
  name: string
  status: string
  abilities: string[]
  last_used_at: string | null
  last_used_ip: string | null
  expires_at: string | null
  revoked_at: string | null
  created_at: string | null
}
