import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '@/lib/api'
import type {
  ApiToken,
  AppNotification,
  Backup,
  BackupFileDownload,
  BackupFileListing,
  BackupFileRestore,
  CountryCurrencyChange,
  DedicatedServer,
  DnsRecord as DnsRecordRow,
  DnsZone,
  Domain,
  DomainContact,
  DomainOperation,
  DomainQuote,
  DomainSearchResult,
  Envelope,
  HostingAccount,
  HostingUsage,
  HostingUsageMeta,
  InstallableTemplate,
  InvitationOffer,
  Invoice,
  IpAssignment,
  NotificationPreference,
  Order,
  OrderQuote,
  Paginated,
  Payment,
  Plan,
  PlanChangeQuote,
  Product,
  RegistrationOptions,
  Service,
  ServiceEvent,
  StartedPayment,
  Subscription,
  TeamInvitation,
  TeamMember,
  ZoneExport,
  ZoneImportMode,
  ZoneImportPlan,
  ZoneImportResult,
  TeamRole,
  Ticket,
  TicketPriority,
  VirtualMachine,
  WalletBalances,
  WalletCreditQuote,
  WalletTransaction,
  WordPressPushImpact,
  WordPressSite,
  WordPressSiteOperation,
} from '@/lib/types'

/**
 * Read and write hooks for the business API.
 *
 * One file rather than one per feature, because these are thin: each is a path,
 * a key and a type. Splitting them across eight directories would put more
 * ceremony than code in each. Anything with a decision in it — what a request
 * means, what a failure means — lives in the feature that owns the screen.
 *
 * Query keys are arrays whose first element is the resource, so invalidating
 * `['orders']` after a checkout catches the list and every detail page at once
 * without anybody maintaining a list of what to invalidate.
 */

function page(searchParams: Record<string, string | number | undefined>): string {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(searchParams)) {
    if (value !== undefined && value !== '') query.set(key, String(value))
  }

  const serialised = query.toString()
  return serialised === '' ? '' : `?${serialised}`
}

/* -------------------------------------------------------------- catalogue */

export function useProducts(kind?: string) {
  return useQuery({
    queryKey: ['catalog', 'products', kind ?? 'all'],
    queryFn: () => api.get<Paginated<Product>>(`/catalog/products${page({ kind })}`),
    // The catalogue changes when an operator edits it, not while a customer is
    // reading it. A minute of staleness saves a request on every navigation.
    staleTime: 60_000,
  })
}

export function useProduct(slugOrId: string) {
  return useQuery({
    queryKey: ['catalog', 'product', slugOrId],
    queryFn: async () => {
      const response = await api.get<Envelope<Product>>(
        `/catalog/products/${encodeURIComponent(slugOrId)}`,
      )
      return response.data
    },
    staleTime: 60_000,
  })
}

export function usePlan(id: string) {
  return useQuery({
    queryKey: ['catalog', 'plan', id],
    queryFn: async () => {
      const response = await api.get<Envelope<Plan>>(`/catalog/plans/${encodeURIComponent(id)}`)
      return response.data
    },
    staleTime: 60_000,
  })
}

/* ----------------------------------------------------------------- orders */

export interface CheckoutPayload {
  /**
   * `items`, as the API names them. The hook sent `lines` until Wave 0 and
   * every checkout was answered "the items field is required" — a refusal
   * the idempotency-key defect (AR-1) hid behind its own 422 until that was
   * fixed. Two contract mismatches, one button.
   */
  items: Array<{ plan_id: string; quantity: number }>
  billing_period: string
  coupon_code?: string
  /**
   * Sent as the `Idempotency-Key` header and never in the body. The server
   * reads it from the header alone, so one key held across retries of the
   * same basket is what makes a double-click one order.
   */
  idempotencyKey: string
}

export function useOrders(pageNumber = 1) {
  return useQuery({
    queryKey: ['orders', pageNumber],
    queryFn: () => api.get<Paginated<Order>>(`/orders${page({ page: pageNumber })}`),
  })
}

export function useOrder(id: string | null) {
  return useQuery({
    queryKey: ['orders', 'detail', id],
    enabled: id !== null && id !== '',
    queryFn: async () => {
      const response = await api.get<Envelope<Order>>(
        `/orders/${encodeURIComponent(id ?? '')}`,
      )
      return response.data
    },
  })
}

/**
 * What the basket in front of the customer costs, from the server.
 *
 * A mutation rather than a query because it is a POST with a body, and because
 * it is asked again whenever the selection changes; it creates nothing, so
 * there is no idempotency key and nothing to invalidate.
 */
export function useOrderQuote() {
  return useMutation({
    mutationFn: async (payload: Omit<CheckoutPayload, 'idempotencyKey'>): Promise<OrderQuote> => {
      const response = await api.post<Envelope<OrderQuote>>('/orders/quote', payload)
      return response.data
    },
  })
}

export function usePlaceOrder() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ idempotencyKey, ...payload }: CheckoutPayload): Promise<Order> => {
      const response = await api.post<Envelope<Order>>('/orders', payload, { idempotencyKey })
      return response.data
    },
    onSuccess: () => {
      // An order produces an invoice, so both lists are stale.
      void queryClient.invalidateQueries({ queryKey: ['orders'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

export function useCancelOrder() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => api.post<unknown>(`/orders/${encodeURIComponent(id)}/cancel`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['orders'] })
    },
  })
}

/* ---------------------------------------------------------------- billing */

export function useInvoices(pageNumber = 1, status?: string) {
  return useQuery({
    queryKey: ['invoices', pageNumber, status ?? 'all'],
    queryFn: () => api.get<Paginated<Invoice>>(`/invoices${page({ page: pageNumber, status })}`),
  })
}

export function useInvoice(id: string) {
  return useQuery({
    queryKey: ['invoices', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<Invoice>>(`/invoices/${encodeURIComponent(id)}`)
      return response.data
    },
  })
}

export function useSubscriptions(pageNumber = 1) {
  return useQuery({
    queryKey: ['subscriptions', pageNumber],
    queryFn: () => api.get<Paginated<Subscription>>(`/subscriptions${page({ page: pageNumber })}`),
  })
}

/**
 * One subscription: what it costs, when it renews, what it pays for.
 *
 * The resource pages read it to show a machine's or a site's commercial half
 * without recomputing any of it — the money is the server's, as Wave 2 left
 * it.
 */
export function useSubscription(id: string | null) {
  return useQuery({
    queryKey: ['subscriptions', 'detail', id],
    enabled: id !== null && id !== '',
    queryFn: async () => {
      const response = await api.get<Envelope<Subscription>>(
        `/subscriptions/${encodeURIComponent(id ?? '')}`,
      )
      return response.data
    },
  })
}

export interface CancellationRequest {
  id: string
  /**
   * True ends it now and does not refund the rest of the period. The server
   * demands the subscription's own id typed back for this form, and the portal
   * forwards what the customer typed rather than the id it already holds.
   */
  immediately?: boolean
  confirmation?: string
}

export function useCancelSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, immediately = false, confirmation }: CancellationRequest) =>
      api.post<unknown>(`/subscriptions/${encodeURIComponent(id)}/cancel`, {
        immediately,
        ...(immediately && confirmation !== undefined ? { confirm_subscription_id: confirmation } : {}),
      }),
    onSuccess: () => {
      // Services as well: a cancellation is the moment a service acquires a
      // date it will be destroyed on, and that date is on the service.
      void queryClient.invalidateQueries({ queryKey: ['subscriptions'] })
      void queryClient.invalidateQueries({ queryKey: ['services'] })
    },
  })
}

/* --------------------------------------------------------------- payments */

export function usePayment(id: string | null) {
  return useQuery({
    queryKey: ['payments', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<Payment>>(`/payments/${encodeURIComponent(id ?? '')}`)
      return response.data
    },
    enabled: id !== null && id !== '',
    /*
     * Read fresh, never from cache: this is the hook a customer returning from
     * a provider's page lands on, and the only honest answer to "did my
     * payment work" is the platform's current record of it.
     */
    staleTime: 0,
  })
}

export function usePayments(pageNumber = 1) {
  return useQuery({
    queryKey: ['payments', pageNumber],
    queryFn: () => api.get<Paginated<Payment>>(`/payments${page({ page: pageNumber })}`),
  })
}

export function useStartPayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (invoiceId: string): Promise<StartedPayment> => {
      const response = await api.post<Envelope<StartedPayment>>(
        `/invoices/${encodeURIComponent(invoiceId)}/payments`,
      )
      return response.data
    },
    onSuccess: () => {
      /*
       * The invoice is NOT marked paid here, and nothing in this client may
       * ever do so. Starting a payment only hands the browser to the provider;
       * whether money arrived is decided server-side by the webhook. The lists
       * are invalidated so the customer sees the attempt, not a settlement.
       */
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

/* --------------------------------------------------------------- services */

export function useServices(pageNumber = 1, kind?: string) {
  return useQuery({
    queryKey: ['services', pageNumber, kind ?? 'all'],
    queryFn: () => api.get<Paginated<Service>>(`/services${page({ page: pageNumber, kind })}`),
  })
}

export function useService(id: string | null) {
  return useQuery({
    queryKey: ['services', 'detail', id],
    enabled: id !== null && id !== '',
    queryFn: async () => {
      const response = await api.get<Envelope<Service>>(
        `/services/${encodeURIComponent(id ?? '')}`,
      )
      return response.data
    },
  })
}

/* -------------------------------------------------------------------- vps */

/**
 * What has happened to one service, in the platform's customer vocabulary.
 *
 * The endpoint has existed since the provisioning engine was built and had no
 * caller: the audit found no activity view anywhere in the portal. This is
 * per-resource only. An account-wide feed is a different thing with a
 * different endpoint, and neither exists yet.
 */
export function useServiceEvents(serviceId: string | null) {
  return useQuery({
    queryKey: ['services', 'events', serviceId],
    enabled: serviceId !== null && serviceId !== '',
    queryFn: () =>
      api.get<Paginated<ServiceEvent>>(
        `/services/${encodeURIComponent(serviceId ?? '')}/events${page({ page: 1 })}`,
      ),
  })
}

export function useVirtualMachines(pageNumber = 1) {
  return useQuery({
    queryKey: ['vps', pageNumber],
    queryFn: () => api.get<Paginated<VirtualMachine>>(`/vps${page({ page: pageNumber })}`),
  })
}

export function useVirtualMachine(id: string) {
  return useQuery({
    queryKey: ['vps', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<VirtualMachine>>(`/vps/${encodeURIComponent(id)}`)
      return response.data
    },
  })
}

/**
 * The operating systems this machine may be rebuilt with.
 *
 * Asked of the machine rather than of a catalogue: the set depends on what is
 * staged where this machine runs, and the reinstall endpoint applies exactly
 * the same rule to whatever id is submitted. Fetched only when the dialogue
 * that needs it is open, because a list page has no use for it.
 */
export function useVpsTemplates(id: string, enabled = true) {
  return useQuery({
    queryKey: ['vps', 'templates', id],
    enabled: enabled && id !== '',
    queryFn: async () => {
      const response = await api.get<{ data: InstallableTemplate[] }>(
        `/vps/${encodeURIComponent(id)}/templates`,
      )
      return response.data
    },
    // Images change when the platform stages one, which is not during a
    // customer's visit.
    staleTime: 5 * 60 * 1000,
  })
}

export interface PowerRequest {
  id: string
  action: string
  /** One per press; sent as the `Idempotency-Key` header. */
  idempotencyKey: string
}

export function useVpsPower() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, action, idempotencyKey }: PowerRequest) =>
      api.post<unknown>(`/vps/${encodeURIComponent(id)}/power`, { action }, { idempotencyKey }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vps'] })
    },
  })
}

export interface ReinstallRequest {
  id: string
  confirm_hostname: string
  /**
   * The image to build from. Omitted means the same one again, which is what
   * the endpoint has always done and what a customer who just wants a clean
   * machine expects.
   */
  template_id?: string
  /**
   * Public keys to install on first boot. Public keys only — a private key
   * has no business travelling anywhere, and the platform has no field for
   * one. Sent only for an image that can be configured on first boot; without
   * that the platform cannot install them and would be collecting something
   * it intends to drop.
   */
  ssh_keys?: string[]
  idempotencyKey: string
}

/**
 * Rebuild a machine.
 *
 * The confirmation is sent as typed. The server compares it against the
 * machine's own hostname and refuses a mismatch — this client does not
 * pre-check it, because a check that lives only here is a check an operator
 * script skips.
 */
export function useVpsReinstall() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, confirm_hostname, template_id, ssh_keys, idempotencyKey }: ReinstallRequest) =>
      api.post<unknown>(
        `/vps/${encodeURIComponent(id)}/reinstall`,
        {
          confirm_hostname,
          // Absent rather than null: the endpoint treats an omitted template
          // as "the same image again", and an empty key list as no keys.
          ...(template_id === undefined ? {} : { template_id }),
          ...(ssh_keys === undefined || ssh_keys.length === 0 ? {} : { ssh_keys }),
        },
        { idempotencyKey },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vps'] })
    },
  })
}

export interface IssuedConsoleSession {
  id: string
  virtual_machine_id: string
  token: string
  gateway: string | null
  expires_in: number
  single_use: boolean
}

/**
 * Mint a console permit.
 *
 * A mutation rather than a query, and never on page load: the permit lives for
 * sixty seconds, so one fetched when a tab was opened has expired before
 * anybody presses connect. It is also single use, which a cache would break by
 * replaying it.
 */
export function useConsoleSession() {
  return useMutation({
    mutationFn: async ({ id }: { id: string }) => {
      const response = await api.get<Envelope<IssuedConsoleSession>>(
        `/vps/${encodeURIComponent(id)}/console`,
      )

      return response.data
    },
  })
}

export function usePlanOptions(subscriptionId: string) {
  return useQuery({
    queryKey: ['subscriptions', subscriptionId, 'plan-options'],
    queryFn: async () => {
      const response = await api.get<{ data: PlanChangeQuote[] }>(
        `/subscriptions/${encodeURIComponent(subscriptionId)}/plan-options`,
      )

      return response.data
    },
  })
}

export interface PlanChangeSubmission {
  subscriptionId: string
  plan_id: string
  price_id: string
  idempotencyKey: string
}

export function useChangePlan() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ subscriptionId, idempotencyKey, ...body }: PlanChangeSubmission) =>
      api.post<unknown>(`/subscriptions/${encodeURIComponent(subscriptionId)}/plan`, body, { idempotencyKey }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['subscriptions'] })
      // The machine's shape changes too, once the hypervisor agrees.
      void queryClient.invalidateQueries({ queryKey: ['vps'] })
    },
  })
}

/* ---------------------------------------------------------- notifications */

/**
 * The inbox. `meta.unread` rides along with the list so the badge needs no
 * second request on every page load.
 */
export function useNotifications(pageNumber = 1, unreadOnly = false) {
  return useQuery({
    queryKey: ['notifications', pageNumber, unreadOnly],
    queryFn: () =>
      api.get<Paginated<AppNotification> & { meta: { unread: number } }>(
        `/notifications${page({ page: pageNumber, ...(unreadOnly ? { unread: 1 } : {}) })}`,
      ),
  })
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => api.post<unknown>(`/notifications/${encodeURIComponent(id)}/read`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => api.post<unknown>('/notifications/read-all'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useNotificationPreferences() {
  return useQuery({
    queryKey: ['notification-preferences'],
    queryFn: () => api.get<Envelope<NotificationPreference[]>>('/me/notification-preferences'),
  })
}

export function useUpdateNotificationPreference() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { category: string; channel: string; enabled: boolean }) =>
      api.put<Envelope<NotificationPreference[]>>('/me/notification-preferences', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notification-preferences'] })
    },
  })
}

/* ---------------------------------------------------------------- backups */

/**
 * Nested under a machine, exactly as the API is. A flat backups collection
 * would need a service id in the query string, and an id supplied by the
 * client is the shape this API avoids everywhere else.
 */
export function useBackups(vmId: string | null, pageNumber = 1) {
  return useQuery({
    queryKey: ['backups', vmId, pageNumber],
    // Not just disabled-when-null: the key carries the machine, so switching
    // machines cannot show the previous one's backups while the new ones load.
    enabled: vmId !== null,
    queryFn: () =>
      api.get<Paginated<Backup>>(
        `/vps/${encodeURIComponent(vmId ?? '')}/backups${page({ page: pageNumber })}`,
      ),
  })
}

export function useCreateBackup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ vmId }: { vmId: string }) =>
      api.post<unknown>(`/vps/${encodeURIComponent(vmId)}/backups`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
  })
}

/**
 * One directory of one backup, read from the provider each time it is
 * asked for. Keyed on the backup and the path: a listing must never be
 * shown under another archive's name.
 */
export function useBackupFiles(vmId: string | null, backupId: string | null, path: string) {
  return useQuery({
    queryKey: ['backups', 'files', vmId, backupId, path],
    enabled: vmId !== null && backupId !== null,
    queryFn: () =>
      api.get<Envelope<BackupFileListing>>(
        `/vps/${encodeURIComponent(vmId ?? '')}/backups/${encodeURIComponent(backupId ?? '')}/files?path=${encodeURIComponent(path)}`,
      ),
  })
}

export function useBackupFileRestores(vmId: string | null, backupId: string | null) {
  return useQuery({
    queryKey: ['backups', 'file-restores', vmId, backupId],
    enabled: vmId !== null && backupId !== null,
    queryFn: () =>
      api.get<{ data: BackupFileRestore[] }>(
        `/vps/${encodeURIComponent(vmId ?? '')}/backups/${encodeURIComponent(backupId ?? '')}/file-restores`,
      ),
  })
}

export function useIssueBackupFileDownload() {
  return useMutation({
    mutationFn: (payload: { vmId: string; backupId: string; path: string }) =>
      api.post<Envelope<BackupFileDownload>>(
        `/vps/${encodeURIComponent(payload.vmId)}/backups/${encodeURIComponent(payload.backupId)}/files/downloads`,
        { path: payload.path },
      ),
  })
}

export function useRestoreBackupFiles() {
  const queryClient = useQueryClient()

  return useMutation({
    /* The typed hostname is forwarded, never filled in: the server compares it
     * and is what decides. */
    mutationFn: (payload: { vmId: string; backupId: string; paths: string[]; confirmation: string }) =>
      api.post<Envelope<BackupFileRestore>>(
        `/vps/${encodeURIComponent(payload.vmId)}/backups/${encodeURIComponent(payload.backupId)}/files/restore`,
        { paths: payload.paths, confirmation: payload.confirmation },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['backups', 'file-restores'] })
    },
  })
}

export interface RestoreRequest {
  vmId: string
  backupId: string
  /** The machine's hostname. The server compares it and is what decides. */
  confirmation: string
}

export function useRestoreBackup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ vmId, backupId, confirmation }: RestoreRequest) =>
      api.post<unknown>(
        `/vps/${encodeURIComponent(vmId)}/backups/${encodeURIComponent(backupId)}/restore`,
        { confirmation },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
      // The machine's own row changes too: a restore takes it out of service
      // for the duration.
      void queryClient.invalidateQueries({ queryKey: ['vps'] })
    },
  })
}

/* -------------------------------------------------------------- dedicated */

export function useDedicatedServers(pageNumber = 1) {
  return useQuery({
    queryKey: ['dedicated', pageNumber],
    queryFn: () => api.get<Paginated<DedicatedServer>>(`/dedicated${page({ page: pageNumber })}`),
  })
}

export function useDedicatedServer(id: string) {
  return useQuery({
    queryKey: ['dedicated', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<DedicatedServer>>(
        `/dedicated/${encodeURIComponent(id)}`,
      )
      return response.data
    },
  })
}

export function useDedicatedPower() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, action, idempotencyKey }: PowerRequest) =>
      api.post<unknown>(`/dedicated/${encodeURIComponent(id)}/power`, { action }, { idempotencyKey }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dedicated'] })
    },
  })
}

export interface DedicatedReinstallRequest {
  id: string
  confirm_serial: string
  idempotencyKey: string
}

/**
 * Rebuild a physical machine.
 *
 * The serial is sent as typed. The server compares it against the machine's
 * own serial and refuses a mismatch; this client does not pre-check it,
 * because a check that lives only here is one an operator script skips.
 */
export function useDedicatedReinstall() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, confirm_serial, idempotencyKey }: DedicatedReinstallRequest) =>
      api.post<unknown>(`/dedicated/${encodeURIComponent(id)}/reinstall`, { confirm_serial }, { idempotencyKey }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dedicated'] })
    },
  })
}

/* ---------------------------------------------------------------- hosting */

export function useHostingAccounts(pageNumber = 1) {
  return useQuery({
    queryKey: ['hosting', pageNumber],
    queryFn: () => api.get<Paginated<HostingAccount>>(`/hosting${page({ page: pageNumber })}`),
  })
}

export function useHostingAccount(id: string) {
  return useQuery({
    queryKey: ['hosting', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<HostingAccount>>(
        `/hosting/${encodeURIComponent(id)}`,
      )
      return response.data
    },
  })
}

/**
 * Disk and bandwidth against quota, as of the platform's last sync.
 *
 * The envelope carries the freshness contract in `meta`, and it is read as
 * well as the figures: a usage bar without "as of" is a claim about now that
 * the platform cannot make.
 */
export function useHostingUsage(id: string) {
  return useQuery({
    queryKey: ['hosting', 'usage', id],
    queryFn: () =>
      api.get<{ data: HostingUsage; meta: HostingUsageMeta }>(
        `/hosting/${encodeURIComponent(id)}/usage`,
      ),
  })
}

export function useHostingSso() {
  return useMutation({
    mutationFn: async (id: string): Promise<{ url: string }> => {
      const response = await api.post<Envelope<{ url: string }>>(
        `/hosting/${encodeURIComponent(id)}/sso`,
      )
      return response.data
    },
  })
}

/* ----------------------------------------------------------------- wallet */

export function useWallet() {
  return useQuery({
    queryKey: ['wallet'],
    // The whole envelope, not `.data`: the account currency in `meta` is what
    // tells the screen which of several balances is the customer's own.
    queryFn: () => api.get<WalletBalances>('/wallet'),
  })
}

/**
 * The wallet ledger, which is where a balance comes from.
 *
 * The portal never reconstructs a balance by adding these rows up: the balance
 * is published by the API, and this list is the history behind it. Two
 * different sums that disagree is exactly how a customer stops trusting both.
 */
export function useWalletTransactions(pageNumber = 1, currency?: string) {
  return useQuery({
    queryKey: ['wallet', 'transactions', pageNumber, currency ?? 'all'],
    queryFn: () =>
      api.get<Paginated<WalletTransaction>>(
        `/wallet/transactions${page({ page: pageNumber, currency })}`,
      ),
  })
}

/* ------------------------------------------------------------------- ipam */

export function useIpAssignments(pageNumber = 1) {
  return useQuery({
    queryKey: ['ips', pageNumber],
    queryFn: () => api.get<Paginated<IpAssignment>>(`/ips${page({ page: pageNumber })}`),
  })
}

export function useSetReverseDns() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, hostname }: { id: string; hostname: string }) =>
      api.put<unknown>(`/ips/${encodeURIComponent(id)}/rdns`, { hostname }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['ips'] })
    },
  })
}

/* ----------------------------------------------------------- api tokens */

/* --------------------------------------------------------------------------
 | Deleting a backup
 |
 | Both mutations invalidate the whole backups key rather than one machine's:
 | the retention ceiling is per service, so removing one backup can change
 | what the list says about the others.
 */

export function useDeleteBackup() {
  const queryClient = useQueryClient()

  return useMutation({
    /*
     * The typed confirmation is forwarded rather than filled in from the id
     * the caller already holds. Sending the known-correct value would make
     * the server's check pass by construction, which is a confirmation only
     * in name.
     */
    mutationFn: (payload: { vmId: string; backupId: string; confirmation: string }) =>
      api.delete<Envelope<Backup>>(
        `/vps/${encodeURIComponent(payload.vmId)}/backups/${encodeURIComponent(payload.backupId)}`,
        { confirm_backup_id: payload.confirmation },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
  })
}

export function useKeepBackup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { vmId: string; backupId: string }) =>
      api.post<Envelope<Backup>>(
        `/vps/${encodeURIComponent(payload.vmId)}/backups/${encodeURIComponent(payload.backupId)}/keep`,
        {},
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
  })
}

/* --------------------------------------------------------------------------
 | Support
 |
 | The list and one ticket are separate queries rather than one filtered
 | client-side: the list deliberately does not carry message threads, and a
 | page that had to load every thread to show a list of subjects would grow
 | with the account's whole support history.
 */

export function useTickets() {
  return useQuery({
    queryKey: ['support', 'tickets'],
    queryFn: () => api.get<{ data: Ticket[]; meta: { total: number } }>('/support/tickets'),
  })
}

export function useTicket(id: string | null) {
  return useQuery({
    queryKey: ['support', 'ticket', id],
    queryFn: () => api.get<Envelope<Ticket>>(`/support/tickets/${encodeURIComponent(id ?? '')}`),
    enabled: id !== null,
  })
}

/**
 * Opening and replying both go up as multipart, always — not only when a file
 * is attached. One code path means the case with attachments is the one that
 * is exercised on every request rather than the rare one nobody tries until a
 * customer does.
 */
function ticketForm(fields: Record<string, string>, files: File[]): FormData {
  const form = new FormData()

  for (const [key, value] of Object.entries(fields)) {
    if (value !== '') form.append(key, value)
  }

  for (const file of files) {
    form.append('attachments[]', file)
  }

  return form
}

export function useOpenTicket() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: {
      subject: string
      body: string
      category: string
      priority: TicketPriority
      service_id?: string
      files: File[]
    }) =>
      api.post<Envelope<Ticket>>(
        '/support/tickets',
        ticketForm(
          {
            subject: payload.subject,
            body: payload.body,
            category: payload.category,
            priority: payload.priority,
            service_id: payload.service_id ?? '',
          },
          payload.files,
        ),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['support'] })
    },
  })
}

export function useReplyToTicket() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { id: string; body: string; files: File[] }) =>
      api.post<Envelope<Ticket>>(
        `/support/tickets/${encodeURIComponent(payload.id)}/replies`,
        ticketForm({ body: payload.body }, payload.files),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['support'] })
    },
  })
}

export function useCloseTicket() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) =>
      api.post<Envelope<Ticket>>(`/support/tickets/${encodeURIComponent(id)}/close`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['support'] })
    },
  })
}

/* --------------------------------------------------------------------------
 | Paying from stored credit
 |
 | The quote is enabled only when a dialogue is open: it is a per-invoice read
 | and fetching one for every row of the invoice list would make a page of
 | twenty invoices twenty-one requests, for numbers nobody has asked to see.
 */

export function useWalletCreditQuote(invoiceId: string | null) {
  return useQuery({
    queryKey: ['invoices', invoiceId, 'wallet-credit'],
    queryFn: () =>
      api.get<Envelope<WalletCreditQuote>>(`/invoices/${encodeURIComponent(invoiceId ?? '')}/wallet-credit`),
    enabled: invoiceId !== null,
    // A balance the customer may have spent on another tab. Refetched each
    // time the dialogue opens rather than served from a cache that could show
    // credit that is already gone.
    staleTime: 0,
  })
}

export function usePayFromWalletCredit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { invoiceId: string; idempotencyKey: string }) =>
      api.post<Envelope<Invoice>>(
        `/invoices/${encodeURIComponent(payload.invoiceId)}/wallet-credit`,
        {},
        // The header, not the body: the server reads it from there and from
        // nowhere else, so a body field of the same name cannot win.
        { idempotencyKey: payload.idempotencyKey },
      ),
    onSuccess: () => {
      // Both moved: the invoice is paid or partly paid, and the balance that
      // paid it is smaller.
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      void queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
  })
}

/* --------------------------------------------------------------------------
 | Team
 |
 | Both lists come back unpaged - an account has at most a couple of dozen
 | members, and a page control over five rows is noise. Every mutation
 | invalidates both queries rather than only the one it changed: accepting an
 | invitation adds a member, removing a member frees a seat the invitation
 | limit counts, and a screen showing one of those refreshed and the other
 | stale is a screen that contradicts itself.
 */

export function useTeamMembers() {
  return useQuery({
    queryKey: ['team', 'members'],
    queryFn: () => api.get<{ data: TeamMember[]; meta: { total: number; limit: number; assignable_roles: TeamRole[] } }>('/team/members'),
  })
}

export function useTeamInvitations(enabled = true) {
  return useQuery({
    queryKey: ['team', 'invitations'],
    queryFn: () => api.get<{ data: TeamInvitation[]; meta: { total: number } }>('/team/invitations'),
    enabled,
  })
}

function useTeamMutation<TVariables, TResult>(
  mutationFn: (variables: TVariables) => Promise<TResult>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['team'] })
    },
  })
}

export function useInviteMember() {
  return useTeamMutation((payload: { email: string; role: TeamRole }) =>
    api.post<Envelope<TeamInvitation>>('/team/invitations', payload),
  )
}

export function useResendInvitation() {
  return useTeamMutation((id: string) =>
    api.post<Envelope<TeamInvitation>>(`/team/invitations/${encodeURIComponent(id)}/resend`, {}),
  )
}

export function useRevokeInvitation() {
  return useTeamMutation((id: string) =>
    api.delete<Envelope<TeamInvitation>>(`/team/invitations/${encodeURIComponent(id)}`),
  )
}

export function useChangeMemberRole() {
  return useTeamMutation((payload: { id: string; role: TeamRole }) =>
    api.patch<Envelope<TeamMember>>(`/team/members/${encodeURIComponent(payload.id)}`, { role: payload.role }),
  )
}

export function useRemoveMember() {
  return useTeamMutation((id: string) => api.delete<unknown>(`/team/members/${encodeURIComponent(id)}`))
}

export function useTransferOwnership() {
  return useTeamMutation((payload: { member_id: string; confirm_account_id: string }) =>
    api.post<Envelope<TeamMember>>('/team/transfer-ownership', payload),
  )
}

/* The invitee's own side. Outside the team queries because the person calling
 * these may belong to no account at all, so nothing about them should be
 * invalidated alongside a team screen they cannot see. */

export function useInvitationOffer(token: string) {
  return useQuery({
    queryKey: ['invitation', token],
    queryFn: () => api.get<Envelope<InvitationOffer>>(`/invitations/${encodeURIComponent(token)}`),
    retry: false,
  })
}

export function useAcceptInvitation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (token: string) =>
      api.post<Envelope<{ customer_id: string; role: TeamRole }>>(`/invitations/${encodeURIComponent(token)}/accept`, {}),
    onSuccess: () => {
      // Joining an account changes what every other query may return.
      void queryClient.invalidateQueries()
    },
  })
}

export function useDeclineInvitation() {
  return useMutation({
    mutationFn: (token: string) =>
      api.post<unknown>(`/invitations/${encodeURIComponent(token)}/decline`, {}),
  })
}

export function useApiTokens() {
  return useQuery({
    queryKey: ['api-tokens'],
    queryFn: () => api.get<Paginated<ApiToken>>('/me/api-tokens'),
  })
}

export function useCreateApiToken() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: {
      name: string
      current_password: string
    }): Promise<{ token: ApiToken; plain_text_token: string }> => {
      const response = await api.post<Envelope<{ token: ApiToken; plain_text_token: string }>>(
        '/me/api-tokens',
        payload,
      )
      return response.data
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    },
  })
}

export function useRevokeApiToken() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => api.delete<unknown>(`/me/api-tokens/${encodeURIComponent(id)}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    },
  })
}

/* --------------------------------------------------------------------------
 | DNS
 |
 | Records are keyed by their zone, so switching zones cannot show one zone's
 | records under another's name while the new ones load — the same reason the
 | backups key carries the machine.
 */

export function useWordPressSites() {
  return useQuery({
    queryKey: ['wordpress', 'sites'],
    queryFn: () => api.get<{ data: WordPressSite[]; meta: { total: number } }>('/wordpress/sites'),

    /*
     * Polled while anything is still being built. A site goes through four
     * steps that finish minutes apart, and a customer watching a screen that
     * only updates on reload is a customer who refreshes it, or opens a
     * ticket.
     */
    refetchInterval: (query) =>
      (query.state.data?.data ?? []).some((site) => !site.is_verified && !site.needs_attention)
        ? 15_000
        : false,
  })
}

export function useWordPressSite(id: string) {
  return useQuery({
    queryKey: ['wordpress', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<WordPressSite>>(
        `/wordpress/sites/${encodeURIComponent(id)}`,
      )
      return response.data
    },
  })
}

export function useOrderWordPressSite() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: {
      domain: string
      domain_source: string
      admin_username: string
      admin_email: string
    }) => api.post<Envelope<WordPressSite>>('/wordpress/sites', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['wordpress'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

export function useDomains() {
  return useQuery({
    queryKey: ['domains'],
    queryFn: () => api.get<{ data: Domain[]; meta: { total: number } }>('/domains'),
  })
}

/**
 * A search, run only when there is something to search for.
 *
 * Not debounced here and not fired per keystroke: the caller submits. Every
 * uncached name is a call against a registrar allowance the whole platform
 * shares, and a search-as-you-type box would spend it on prefixes nobody
 * meant to look up.
 */
export function useDomainSearch(name: string) {
  return useQuery({
    queryKey: ['domains', 'search', name],
    enabled: name.trim() !== '',
    queryFn: () =>
      api.get<{ data: DomainSearchResult[]; meta: { total: number } }>(
        `/domains/search?name=${encodeURIComponent(name.trim())}`,
      ),
  })
}

export function useQuoteDomain() {
  return useMutation({
    mutationFn: (payload: { name: string; operation: string; term_years?: number }) =>
      api.post<Envelope<DomainQuote>>('/domains/quotes', payload),
  })
}

export function useOrderDomain() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { quote_id: string; registrant: Record<string, string> }) =>
      api.post<Envelope<DomainOperation>>('/domains', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
      // The order issues an invoice, and the customer is about to be asked to
      // pay it. A stale billing list here is a customer who cannot find it.
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

/**
 * One name, by its own name or by its id.
 *
 * The portal's address for a domain is the domain — `/domains/example.com` —
 * and the API resolves either form inside the acting account, so a deep link
 * works on a cold load with no list behind it.
 */
export function useDomain(identity: string) {
  return useQuery({
    queryKey: ['domains', 'detail', identity],
    queryFn: async () => {
      const response = await api.get<Envelope<Domain>>(
        `/domains/${encodeURIComponent(identity)}`,
      )
      return response.data
    },
  })
}

/**
 * The registrant on record.
 *
 * Its own request rather than a field on the domain: it is personal data
 * behind a stricter permission, and a list of names has no business carrying
 * a home address for each one.
 */
export function useDomainContacts(identity: string, enabled = true) {
  return useQuery({
    queryKey: ['domains', 'contacts', identity],
    enabled: enabled && identity !== '',
    queryFn: async () => {
      const response = await api.get<Envelope<DomainContact>>(
        `/domains/${encodeURIComponent(identity)}/contacts`,
      )
      return response.data
    },
    // Not cached across a visit: it is read to fill a form, and a stale copy
    // would silently re-submit what the customer just corrected.
    staleTime: 0,
  })
}

export interface RegistrantPayload {
  name: string
  organisation?: string | null
  email: string
  phone: string
  address_line_one: string
  address_line_two?: string | null
  city: string
  region?: string | null
  postal_code?: string | null
  country: string
}

export function useUpdateDomainContacts(identity: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (registrant: RegistrantPayload) =>
      api.put<Envelope<Domain>>(`/domains/${encodeURIComponent(identity)}/contacts`, {
        registrant,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
    },
  })
}

/**
 * Whether the platform raises the next invoice before this name lapses.
 *
 * A setting, so no idempotency key and no typed confirmation — it is
 * reversible in one click. What it is not is a cancellation, and the screen
 * says so beside the switch.
 */
export function useSetDomainAutoRenew(identity: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (autoRenew: boolean) =>
      api.put<Envelope<Domain>>(`/domains/${encodeURIComponent(identity)}/auto-renew`, {
        auto_renew: autoRenew,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
    },
  })
}

/**
 * Bringing a name in from another registrar.
 *
 * Not nested under a domain, because the platform does not hold it yet. The
 * quote is taken first — the transfer costs a term — and the authorisation
 * code travels once and is never rendered back.
 */
export function useTransferDomainIn() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { quote_id: string; authorisation_code: string }) =>
      api.post<Envelope<DomainOperation>>('/domains/transfers', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

export function useRenewDomain(domainId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { quote_id: string }) =>
      api.post<Envelope<DomainOperation>>(
        `/domains/${encodeURIComponent(domainId)}/renewals`,
        payload,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

export function useRedeemDomain(domainId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { quote_id: string }) =>
      api.post<Envelope<DomainOperation>>(
        `/domains/${encodeURIComponent(domainId)}/redemptions`,
        payload,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}

export function useSetDomainNameservers(domainId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { nameservers: string[] }) =>
      api.put<Envelope<Domain>>(
        `/domains/${encodeURIComponent(domainId)}/nameservers`,
        payload,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
    },
  })
}

export function useSetDomainTransferLock(domainId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { locked: boolean }) =>
      api.put<Envelope<Domain>>(
        `/domains/${encodeURIComponent(domainId)}/transfer-lock`,
        payload,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['domains'] })
    },
  })
}

/**
 * The code that lets the customer take the name elsewhere.
 *
 * A mutation rather than a query, and deliberately not cached: it is a bearer
 * credential for the whole domain, and a cached copy would sit in the client's
 * memory long after the tab that asked for it moved on.
 */
export function useDomainAuthorisationCode(domainId: string) {
  return useMutation({
    mutationFn: () =>
      api.post<Envelope<{ authorisation_code: string }>>(
        `/domains/${encodeURIComponent(domainId)}/authorisation-code`,
        {},
      ),
  })
}

export function useDnsZones() {
  return useQuery({
    queryKey: ['dns', 'zones'],
    queryFn: () => api.get<{ data: DnsZone[]; meta: { total: number } }>('/dns/zones'),
  })
}

export function useDnsRecords(zoneId: string | null) {
  return useQuery({
    queryKey: ['dns', 'records', zoneId],
    enabled: zoneId !== null,
    queryFn: () =>
      api.get<{ data: DnsRecordRow[]; meta: { total: number } }>(
        `/dns/zones/${encodeURIComponent(zoneId ?? '')}/records`,
      ),
  })
}

/**
 * One zone, by its name or its id — the same two forms a domain accepts.
 */
export function useDnsZone(identity: string) {
  return useQuery({
    queryKey: ['dns', 'zones', 'detail', identity],
    queryFn: async () => {
      const response = await api.get<Envelope<DnsZone>>(
        `/dns/zones/${encodeURIComponent(identity)}`,
      )
      return response.data
    },
  })
}

export function useClaimDnsZone() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { name: string }) =>
      api.post<Envelope<DnsZone>>('/dns/zones', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns'] })
    },
  })
}

export function useReleaseDnsZone() {
  const queryClient = useQueryClient()

  return useMutation({
    /* The typed name is forwarded rather than filled in from the zone the
     * caller already holds: a confirmation the client completes for you is a
     * confirmation in name only. */
    mutationFn: (payload: { zoneId: string; confirmation: string }) =>
      api.delete<Envelope<DnsZone>>(`/dns/zones/${encodeURIComponent(payload.zoneId)}`, {
        confirm_zone_name: payload.confirmation,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns'] })
    },
  })
}

export interface NewDnsRecord {
  zoneId: string
  type: string
  name: string
  content?: string
  ttl?: number
  priority?: number | null
  data?: { flags: number; tag: string; value: string }
}

export function useAddDnsRecord() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ zoneId, ...body }: NewDnsRecord) =>
      api.post<Envelope<DnsRecordRow>>(
        `/dns/zones/${encodeURIComponent(zoneId)}/records`,
        body,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns'] })
    },
  })
}

/**
 * Change a record in place.
 *
 * The canonical PATCH, not a remove-and-add: the portal used to offer no edit
 * at all, and simulating one by deleting the record first would take the name
 * off the internet for as long as the second request took — and would leave
 * the zone with nothing at all if that request failed.
 */
export function useUpdateDnsRecord() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      zoneId,
      recordId,
      changes,
    }: {
      zoneId: string
      recordId: string
      changes: Partial<Pick<NewDnsRecord, 'content' | 'ttl' | 'priority'>>
    }) =>
      api.patch<Envelope<DnsRecordRow>>(
        `/dns/zones/${encodeURIComponent(zoneId)}/records/${encodeURIComponent(recordId)}`,
        changes,
      ),
    onSuccess: (_result, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['dns', 'records', variables.zoneId] })
    },
  })
}

export function useRemoveDnsRecord() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { zoneId: string; recordId: string }) =>
      api.delete<Envelope<DnsRecordRow>>(
        `/dns/zones/${encodeURIComponent(payload.zoneId)}/records/${encodeURIComponent(payload.recordId)}`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns'] })
    },
  })
}

/**
 * A zone file read against the zone: what would change, line by line, and
 * nothing written. Not cached — the plan is computed against the zone as it
 * is at that moment, and a stale one is exactly what the fingerprint refuses.
 */
export function usePlanZoneImport() {
  return useMutation({
    mutationFn: (payload: { zoneId: string; text: string; mode: ZoneImportMode }) =>
      api.post<Envelope<ZoneImportPlan>>(
        `/dns/zones/${encodeURIComponent(payload.zoneId)}/import/plan`,
        { text: payload.text, mode: payload.mode },
      ),
  })
}

export function useApplyZoneImport() {
  const queryClient = useQueryClient()

  return useMutation({
    /* The same text and mode the preview was given, plus its fingerprint: the
     * server recomputes the plan and applies it only if it is the one that was
     * shown. */
    mutationFn: (payload: { zoneId: string; text: string; mode: ZoneImportMode; fingerprint: string }) =>
      api.post<Envelope<ZoneImportResult>>(`/dns/zones/${encodeURIComponent(payload.zoneId)}/import`, {
        text: payload.text,
        mode: payload.mode,
        fingerprint: payload.fingerprint,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns'] })
    },
  })
}

export function useExportZone() {
  return useMutation({
    mutationFn: (zoneId: string) =>
      api.get<Envelope<ZoneExport>>(`/dns/zones/${encodeURIComponent(zoneId)}/export`),
  })
}

/* ------------------------------------------------- the account's currency */

/**
 * Requests to change what the account is billed in, with the currencies the
 * catalogue prices anything in — the only ones an account can be moved to.
 */
export function useCountryCurrencyChanges() {
  return useQuery({
    queryKey: ['account', 'country-currency-changes'],
    queryFn: () =>
      api.get<{ data: CountryCurrencyChange[]; meta: { currencies: string[] } }>('/account/country-currency-changes'),
  })
}

export function useRequestCountryCurrencyChange() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { country: string | null; currency: string; reason: string }) =>
      api.post<Envelope<CountryCurrencyChange>>('/account/country-currency-changes', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['account'] })
      void queryClient.invalidateQueries({ queryKey: ['auth'] })
    },
  })
}

export function useReanalyseCountryCurrencyChange() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) =>
      api.post<Envelope<CountryCurrencyChange>>(`/account/country-currency-changes/${encodeURIComponent(id)}/reanalyse`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['account'] })
    },
  })
}

export function useWithdrawCountryCurrencyChange() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) =>
      api.post<Envelope<CountryCurrencyChange>>(`/account/country-currency-changes/${encodeURIComponent(id)}/withdraw`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['account'] })
    },
  })
}

/* ----------------------------------------------- WordPress copies and pushes */

export function useWordPressSiteOperations(siteId: string) {
  return useQuery({
    queryKey: ['wordpress', 'operations', siteId],
    queryFn: () =>
      api.get<{ data: WordPressSiteOperation[] }>(`/wordpress/sites/${encodeURIComponent(siteId)}/operations`),
  })
}

export function useCreateWordPressStaging() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (siteId: string) =>
      api.post<Envelope<WordPressSiteOperation>>(`/wordpress/sites/${encodeURIComponent(siteId)}/staging`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['wordpress'] })
    },
  })
}

export function useCloneWordPressSite() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: { siteId: string; domain: string }) =>
      api.post<Envelope<WordPressSiteOperation>>(`/wordpress/sites/${encodeURIComponent(payload.siteId)}/clones`, {
        domain: payload.domain,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['wordpress'] })
    },
  })
}

/** Fetched on demand, when the customer opens the push confirmation: the words it shows. */
export function useWordPressPushImpact() {
  return useMutation({
    mutationFn: (payload: { siteId: string; scope: string }) =>
      api.get<Envelope<WordPressPushImpact>>(
        `/wordpress/sites/${encodeURIComponent(payload.siteId)}/push/impact?scope=${encodeURIComponent(payload.scope)}`,
      ),
  })
}

export function usePushWordPressToProduction() {
  const queryClient = useQueryClient()

  return useMutation({
    /* The typed domain travels as typed: the server compares it and decides. */
    mutationFn: (payload: { siteId: string; scope: string; confirmation: string }) =>
      api.post<Envelope<WordPressSiteOperation>>(`/wordpress/sites/${encodeURIComponent(payload.siteId)}/push`, {
        scope: payload.scope,
        confirmation: payload.confirmation,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['wordpress'] })
    },
  })
}

/* --------------------------------------------------- registration options */

/**
 * The countries and currencies registration may offer.
 *
 * Read from the server, never assembled here: a country-to-currency table in
 * this bundle would drift from the platform's the first time a currency was
 * enabled or withdrawn, and the customer would find out at checkout.
 */
export function useRegistrationOptions() {
  return useQuery({
    queryKey: ['registration', 'options'],
    queryFn: async () => {
      const response = await api.get<Envelope<RegistrationOptions>>('/registration/options')
      return response.data
    },
    // Configuration, not anybody's data: worth holding for the length of a
    // sign-up rather than re-fetching per keystroke.
    staleTime: 10 * 60 * 1000,
  })
}

/* ------------------------------------------------- email verification */

export function useResendVerificationEmail() {
  return useMutation({
    mutationFn: () => api.post<unknown>('/email/verify/resend'),
  })
}

/* ------------------------------------------------- the controlled gateway */

/**
 * The fake provider's own payment page.
 *
 * These hooks exist so a test and a developer can walk the redirect flow
 * end to end in a browser. The endpoints behind them are refused whenever a
 * real payment provider is configured and refused outright in production, and
 * they settle nothing themselves: approving sends the platform a signed
 * webhook, and the invoice is settled by that.
 */
export interface ControlledGatewayPage {
  payment: Payment
  provider: string
  reference: string
}

export function useControlledGatewayPayment(reference: string | null) {
  return useQuery({
    queryKey: ['fake-gateway', reference],
    queryFn: async () => {
      const response = await api.get<Envelope<ControlledGatewayPage>>(
        `/fake-gateway/payments/${encodeURIComponent(reference ?? '')}`,
      )
      return response.data
    },
    enabled: reference !== null && reference !== '',
    staleTime: 0,
    retry: false,
  })
}

export function useControlledGatewayDecision() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      reference,
      decision,
      clientSecret,
    }: {
      reference: string
      decision: 'approve' | 'decline' | 'confirm'
      clientSecret?: string
    }): Promise<Payment> => {
      const response = await api.post<Envelope<{ payment: Payment }>>(
        `/fake-gateway/payments/${encodeURIComponent(reference)}/${decision}`,
        decision === 'confirm' ? { client_secret: clientSecret } : undefined,
      )

      return response.data.payment
    },
    onSuccess: () => {
      // The invoice may now be settled — by the webhook, not by this response.
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
  })
}
