import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '@/lib/api'
import type {
  ApiToken,
  DedicatedServer,
  Envelope,
  HostingAccount,
  Invoice,
  IpAssignment,
  Order,
  Paginated,
  Payment,
  Plan,
  Product,
  Service,
  Subscription,
  VirtualMachine,
  WalletBalance,
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
    queryFn: async () => {
      const response = await api.get<Envelope<WalletBalance>>('/wallet')
      return response.data
    },
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
