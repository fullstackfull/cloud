/**
 * The one list of where the portal can take a person.
 *
 * Both renderers — the desktop bar with its "More" menu and the phone drawer —
 * read from here. The audit found the drawer showing four of twenty-one
 * destinations because it had its own, shorter list; two lists that mean the
 * same thing will disagree again the day one of them is edited. There is now
 * one, and a unit test walks it to prove the drawer offers every entry.
 *
 * Only what is actually reachable is listed. The navigation is not a roadmap:
 * a link to a page that does not exist tells a customer the platform can do
 * something it cannot, and they will open a ticket about it. Entries appear
 * here as their routes are built.
 */
export interface NavItem {
  to: string
  labelKey: string
  /** Match this route exactly, so a section's index link is not lit on every child. */
  end?: boolean
}

/** What a customer opens most; the desktop bar shows these in the open. */
export const PRIMARY_NAV: readonly NavItem[] = [
  { to: '/', labelKey: 'nav.dashboard' },
  { to: '/catalogue', labelKey: 'nav.catalogue' },
  { to: '/services', labelKey: 'nav.services' },
  { to: '/invoices', labelKey: 'nav.invoices' },
]

/**
 * Everything else a customer can reach. On the desktop these sit behind
 * "More"; on a phone they are simply the rest of the drawer.
 */
export const SECONDARY_NAV: readonly NavItem[] = [
  { to: '/orders', labelKey: 'nav.orders' },
  { to: '/subscriptions', labelKey: 'nav.subscriptions' },
  // Payment history sits beside the wallet, because the two answer the same
  // question from different sides: what has been paid, and what is left.
  { to: '/payments', labelKey: 'nav.payments' },
  { to: '/wallet', labelKey: 'nav.wallet' },
  { to: '/vps', labelKey: 'nav.vps' },
  { to: '/backups', labelKey: 'nav.backups' },
  { to: '/notifications', labelKey: 'nav.notifications' },
  { to: '/dedicated', labelKey: 'nav.dedicated' },
  { to: '/hosting', labelKey: 'nav.hosting' },
  { to: '/ips', labelKey: 'nav.ips' },
  { to: '/dns', labelKey: 'nav.dns' },
  { to: '/domains', labelKey: 'nav.domains' },
  { to: '/wordpress', labelKey: 'nav.wordpress' },
  { to: '/api-tokens', labelKey: 'nav.apiKeys' },
  { to: '/support', labelKey: 'nav.support' },
  { to: '/settings/team', labelKey: 'nav.team' },
  { to: '/profile', labelKey: 'nav.profile' },
  { to: '/security', labelKey: 'nav.security' },
]

/** Every customer destination, in the order a person reads them. */
export const CUSTOMER_NAV: readonly NavItem[] = [...PRIMARY_NAV, ...SECONDARY_NAV]

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
