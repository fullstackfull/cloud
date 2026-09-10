/**
 * The one list of where the portal can take a person.
 *
 * Both renderers — the desktop sidebar and the phone drawer — read from here.
 * The audit found the drawer showing four of twenty-one destinations because it
 * had its own, shorter list; two lists that mean the same thing will disagree
 * again the day one of them is edited. There is one, and a unit test walks it
 * to prove the drawer offers every entry the sidebar does.
 *
 * Only what is actually reachable is listed. The navigation is not a roadmap:
 * a link to a page that does not exist tells a customer the platform can do
 * something it cannot, and they will open a ticket about it. Entries appear
 * here as their routes are built — which is why there is no Activity group,
 * although the target architecture has one: the account-wide feed it would
 * point at does not exist yet.
 */
export interface NavItem {
  to: string
  labelKey: string
  /** Match this route exactly, so a section's index link is not lit on every child. */
  end?: boolean
}

/**
 * A heading and the destinations under it.
 *
 * `labelKey` is null for the groups that are a single destination — Dashboard,
 * Support, Notifications. They are rendered as one link with no heading,
 * because a heading over a list of one is furniture.
 */
export interface NavGroup {
  id: string
  labelKey: string | null
  items: readonly NavItem[]
}

/**
 * The customer's navigation, grouped as the platform is actually shaped:
 * something to buy, the things you own, what they cost, how to get help, and
 * your account.
 *
 * The order is the order a customer meets them. Services leads with "All
 * services" because the answer to "what do I have?" should not require knowing
 * which family a thing belongs to; the families follow for when they do.
 */
export const CUSTOMER_NAV_GROUPS: readonly NavGroup[] = [
  {
    id: 'home',
    labelKey: null,
    items: [{ to: '/', labelKey: 'nav.dashboard' }],
  },
  {
    /*
     * One destination, so no heading: "Buy" over a list containing only
     * "Catalogue" was two words for the same act. The customer's word is the
     * one that survived — a person buys a server; a catalogue is what the
     * platform calls its own price list.
     */
    id: 'buy',
    labelKey: null,
    items: [{ to: '/catalogue', labelKey: 'nav.buy' }],
  },
  {
    id: 'services',
    labelKey: 'nav.groups.services',
    items: [
      { to: '/services', labelKey: 'nav.allServices' },
      { to: '/vps', labelKey: 'nav.vps' },
      { to: '/dedicated', labelKey: 'nav.dedicated' },
      { to: '/hosting', labelKey: 'nav.hosting' },
      { to: '/wordpress', labelKey: 'nav.wordpress' },
      { to: '/domains', labelKey: 'nav.domains' },
      { to: '/dns', labelKey: 'nav.dns' },
      { to: '/ips', labelKey: 'nav.ips' },
      { to: '/backups', labelKey: 'nav.backups' },
    ],
  },
  {
    id: 'billing',
    labelKey: 'nav.groups.billing',
    items: [
      { to: '/orders', labelKey: 'nav.orders' },
      { to: '/invoices', labelKey: 'nav.invoices' },
      // Payment history sits beside the wallet, because the two answer the
      // same question from different sides: what has been paid, and what is
      // left.
      { to: '/payments', labelKey: 'nav.payments' },
      { to: '/subscriptions', labelKey: 'nav.subscriptions' },
      { to: '/wallet', labelKey: 'nav.wallet' },
    ],
  },
  {
    id: 'support',
    labelKey: null,
    items: [{ to: '/support', labelKey: 'nav.support' }],
  },
  {
    id: 'notifications',
    labelKey: null,
    items: [{ to: '/notifications', labelKey: 'nav.notifications' }],
  },
  {
    id: 'account',
    labelKey: 'nav.groups.account',
    items: [
      { to: '/profile', labelKey: 'nav.profile' },
      { to: '/settings/team', labelKey: 'nav.team' },
      { to: '/security', labelKey: 'nav.security' },
      { to: '/api-tokens', labelKey: 'nav.apiKeys' },
    ],
  },
]

/**
 * Every customer destination, flattened.
 *
 * Derived rather than written a second time: the groups are the source, and a
 * renderer or a test that wants the plain list gets it from them.
 */
export const CUSTOMER_NAV: readonly NavItem[] = CUSTOMER_NAV_GROUPS.flatMap(
  (group) => [...group.items],
)

/**
 * Shown only to a login that holds at least one operator permission.
 *
 * Hiding it is a courtesy, not a control: the endpoints behind these screens
 * each check their own permission, and a customer who types the URL gets a 403
 * from every request the page makes.
 */
export const OPERATOR_NAV: readonly NavItem[] = [
  { to: '/admin/customers', labelKey: 'admin.nav.customers' },
  { to: '/admin/account-changes', labelKey: 'admin.nav.accountChanges' },
  { to: '/admin/provisioning', labelKey: 'admin.nav.provisioning' },
  { to: '/admin/operations', labelKey: 'admin.nav.operations' },
  { to: '/admin/drift', labelKey: 'admin.nav.drift' },
  { to: '/admin/support', labelKey: 'admin.nav.support' },
  { to: '/admin/infrastructure', labelKey: 'admin.nav.infrastructure' },
  { to: '/admin/payments', labelKey: 'admin.nav.payments' },
]

/**
 * The Control Center: one navigation area over three bounded concerns —
 * Infrastructure, Providers and Product Readiness. A composition of screens,
 * not a module of its own; each screen's requests go to its own concern's API.
 */
export const CONTROL_CENTER_NAV: readonly NavItem[] = [
  { to: '/admin/control-center', labelKey: 'admin.nav.overview', end: true },
  { to: '/admin/control-center/sites', labelKey: 'admin.nav.sites' },
  { to: '/admin/control-center/machines', labelKey: 'admin.nav.machines' },
  { to: '/admin/control-center/providers', labelKey: 'admin.nav.providers' },
  { to: '/admin/control-center/discovery', labelKey: 'admin.nav.discovery' },
  { to: '/admin/control-center/plans', labelKey: 'admin.nav.plans' },
  { to: '/admin/control-center/deployments', labelKey: 'admin.nav.deployments' },
  { to: '/admin/control-center/readiness', labelKey: 'admin.nav.readiness' },
  { to: '/admin/control-center/credentials', labelKey: 'admin.nav.credentials' },
  { to: '/admin/control-center/licences', labelKey: 'admin.nav.licences' },
]

/**
 * The operator areas, as groups, so both renderers lay them out the same way.
 *
 * The customer groups and these are separate exports rather than one list with
 * a permission field: an operator's screens are a different product, and the
 * audit's complaint about the old "More" menu was precisely that it ran the
 * two together into one undifferentiated list of thirty-five links.
 */
export const OPERATOR_NAV_GROUPS: readonly NavGroup[] = [
  { id: 'operator', labelKey: 'admin.nav.section', items: OPERATOR_NAV },
  {
    id: 'control-center',
    labelKey: 'admin.controlCenter.section',
    items: CONTROL_CENTER_NAV,
  },
]
