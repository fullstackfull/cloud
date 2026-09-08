import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '@/lib/api'
import type {
  ApiToken,
  AppNotification,
  Backup,
  DedicatedServer,
  Envelope,
  HostingAccount,
  InvitationOffer,
  Invoice,
  IpAssignment,
  NotificationPreference,
  Order,
  Paginated,
  Payment,
  Plan,
  PlanChangeQuote,
  Product,
  Service,
  Subscription,
  TeamInvitation,
  TeamMember,
  TeamRole,
  VirtualMachine,
  WalletBalances,
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
  lines: Array<{ plan_id: string; quantity: number }>
  billing_period: string
  coupon_code?: string
  idempotency_key: string
}

export function useOrders(pageNumber = 1) {
  return useQuery({
    queryKey: ['orders', pageNumber],
    queryFn: () => api.get<Paginated<Order>>(`/orders${page({ page: pageNumber })}`),
  })
}

export function useOrder(id: string) {
  return useQuery({
    queryKey: ['orders', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<Order>>(`/orders/${encodeURIComponent(id)}`)
      return response.data
    },
  })
}

export function usePlaceOrder() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: CheckoutPayload): Promise<Order> => {
      const response = await api.post<Envelope<Order>>('/orders', payload)
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

export function useCancelSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) =>
      api.post<unknown>(`/subscriptions/${encodeURIComponent(id)}/cancel`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['subscriptions'] })
    },
  })
}

/* --------------------------------------------------------------- payments */

export interface StartedPayment {
  payment_id: string
  provider: string
  /** Where the browser must go next, when the provider needs a redirect. */
  redirect_url?: string | null
  client_secret?: string | null
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

export function useService(id: string) {
  return useQuery({
    queryKey: ['services', 'detail', id],
    queryFn: async () => {
      const response = await api.get<Envelope<Service>>(`/services/${encodeURIComponent(id)}`)
      return response.data
    },
  })
}

/* -------------------------------------------------------------------- vps */

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

export interface PowerRequest {
  id: string
  action: string
  idempotency_key: string
}

export function useVpsPower() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, action, idempotency_key }: PowerRequest) =>
      api.post<unknown>(`/vps/${encodeURIComponent(id)}/power`, { action, idempotency_key }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vps'] })
    },
  })
}

export interface ReinstallRequest {
  id: string
  confirm_hostname: string
  idempotency_key: string
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
    mutationFn: ({ id, confirm_hostname, idempotency_key }: ReinstallRequest) =>
      api.post<unknown>(`/vps/${encodeURIComponent(id)}/reinstall`, {
        confirm_hostname,
        idempotency_key,
      }),
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
  idempotency_key: string
}

export function useChangePlan() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ subscriptionId, ...body }: PlanChangeSubmission) =>
      api.post<unknown>(`/subscriptions/${encodeURIComponent(subscriptionId)}/plan`, body),
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

export function useDedicatedPower() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, action, idempotency_key }: PowerRequest) =>
      api.post<unknown>(`/dedicated/${encodeURIComponent(id)}/power`, { action, idempotency_key }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dedicated'] })
    },
  })
}

export interface DedicatedReinstallRequest {
  id: string
  confirm_serial: string
  idempotency_key: string
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
    mutationFn: ({ id, confirm_serial, idempotency_key }: DedicatedReinstallRequest) =>
      api.post<unknown>(`/dedicated/${encodeURIComponent(id)}/reinstall`, {
        confirm_serial,
        idempotency_key,
      }),
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
