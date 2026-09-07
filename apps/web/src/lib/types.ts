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
