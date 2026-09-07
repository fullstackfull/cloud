import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { request } from '@/lib/api'
import type { Envelope, Money, Paginated } from '@/lib/types'

/**
 * The administrative surface.
 *
 * It lives under a different prefix from the customer API and therefore cannot
 * go through the `api` helper, which is bound to `/api/v1`. That separation is
 * deliberate rather than incidental: a mistyped path cannot land an operator
 * call on a customer endpoint or the reverse.
 */
function adminPath(path: string, params: Record<string, string | number | undefined> = {}): string {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') query.set(key, String(value))
  }

  const serialised = query.toString()
  return `/api/admin${path}${serialised === '' ? '' : `?${serialised}`}`
}

const admin = {
  get: <T>(path: string, params?: Record<string, string | number | undefined>) =>
    request<T>(adminPath(path, params), { method: 'GET', absolute: true }),
  post: <T>(path: string, body?: unknown) =>
    request<T>(adminPath(path), { method: 'POST', body, absolute: true }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(adminPath(path), { method: 'PUT', body, absolute: true }),
}

export interface AdminCustomer {
  id: string
  type: string
  status: string
  display_name: string
  legal_name: string | null
  billing_email: string | null
  currency: string
  country: string | null
  created_at: string | null
}

export function useAdminCustomers(page: number, search: string) {
  return useQuery({
    queryKey: ['admin', 'customers', page, search],
    queryFn: () => admin.get<Paginated<AdminCustomer>>('/customers', { page, q: search }),
  })
}

export function useSetCustomerStatus() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, status, reason }: { id: string; status: string; reason: string }) =>
      admin.put<Envelope<AdminCustomer>>(`/customers/${encodeURIComponent(id)}/status`, {
        status,
        reason,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'customers'] })
    },
  })
}

export interface AdminProvisioningJob {
  id: string
  kind: string
  status: string
  provider?: string
  customer_id: string | null
  service_id?: string | null
  attempts: number
  max_attempts?: number
  failure_class: string | null
  last_error: string | null
  correlation_id?: string | null
  created_at: string | null
}

export function useAdminProvisioningJobs(page: number, status?: string) {
  return useQuery({
    queryKey: ['admin', 'provisioning', page, status ?? 'all'],
    queryFn: () => admin.get<Paginated<AdminProvisioningJob>>('/provisioning/jobs', { page, status }),
    // The queue moves while an operator watches it, unlike a catalogue.
    refetchInterval: 15_000,
  })
}

export function useJobsNeedingReview() {
  return useQuery({
    queryKey: ['admin', 'provisioning', 'needs-review'],
    queryFn: () => admin.get<Paginated<AdminProvisioningJob>>('/provisioning/needs-review'),
    refetchInterval: 15_000,
  })
}

export interface AdminNode {
  id: string
  name: string
  cluster: string | null
  datacenter: string | null
  status: string
  is_healthy: boolean
  vm_count: number
  cpu: { cores: number; allocatable: number; allocated: number }
  memory_mib: { total: number; allocatable: number; allocated: number }
  storage_gib: { total: number; allocated: number }
  last_seen_at: string | null
}

export function useAdminNodes(page: number) {
  return useQuery({
    queryKey: ['admin', 'nodes', page],
    queryFn: () => admin.get<Paginated<AdminNode>>('/infrastructure/nodes', { page }),
  })
}

export interface AdminHostingNode {
  id: string
  slug: string
  hostname: string
  panel: string
  status: string
  accepts_new_accounts: boolean
  panel_licensed: boolean
  licence_status: string | null
  account_count: number
  max_accounts: number | null
  disk_used_mib: number | null
  disk_total_mib: number | null
  load_average: number | null
}

export function useAdminHostingNodes(page: number) {
  return useQuery({
    queryKey: ['admin', 'hosting-nodes', page],
    queryFn: () => admin.get<Paginated<AdminHostingNode>>('/infrastructure/hosting-nodes', { page }),
  })
}

export interface AdminTransaction {
  id: string
  kind: string
  status: string
  provider: string
  amount: Money
  invoice_id: string | null
  customer_id: string | null
  processed_at: string | null
  created_at: string | null
}

export function useAdminTransactions(page: number, status?: string) {
  return useQuery({
    queryKey: ['admin', 'transactions', page, status ?? 'all'],
    queryFn: () => admin.get<Paginated<AdminTransaction>>('/transactions', { page, status }),
  })
}

export function useIssueRefund() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      transactionId,
      amountMinor,
      reason,
    }: {
      transactionId: string
      amountMinor: number
      reason: string
    }) =>
      admin.post<Envelope<{ id: string; amount: Money }>>(
        `/transactions/${encodeURIComponent(transactionId)}/refunds`,
        // Minor units, never a decimal. A decimal in this field is a float
        // somewhere between the form and the ledger.
        { amount_minor: amountMinor, reason },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'transactions'] })
    },
  })
}
