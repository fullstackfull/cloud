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

export function useRetryProvisioningJob() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, evidence }: { id: string; evidence: string }) =>
      admin.post<Envelope<AdminProvisioningJob>>(
        `/provisioning/jobs/${encodeURIComponent(id)}/retry`,
        { evidence },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'provisioning'] })
    },
  })
}

/**
 * One rebuild of one machine, of either kind.
 *
 * `data_destroyed` is the field an operator answers the phone with, and it is
 * read from the moment the operation entered its destructive phase rather than
 * inferred from the state: a rebuild that failed afterwards still destroyed
 * the disk.
 */
export interface AdminReinstallOperation {
  id: string
  type: 'vps_reinstall' | 'dedicated_reinstall'
  state: string
  needs_attention: boolean
  in_flight: boolean
  data_destroyed: boolean
  customer_id: string | null
  service_id: string | null
  subject_type: string
  subject_id: string | null
  provider_resource_id: string | null
  provider_node: string | null
  provisioning_job_id: string | null
  failure_code: string | null
  failure_message: string | null
  requested_at: string | null
  state_changed_at: string | null
  completed_at: string | null
}

export function useReinstallOperations(page: number, onlyWaiting: boolean) {
  return useQuery({
    queryKey: ['admin', 'operations', 'reinstalls', page, onlyWaiting],
    queryFn: () =>
      admin.get<Paginated<AdminReinstallOperation>>('/operations/reinstalls', {
        page,
        needs_attention: onlyWaiting ? 1 : undefined,
      }),
    refetchInterval: 30_000,
  })
}

export function useResolveReinstall() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      type,
      id,
      verdict,
      evidence,
    }: {
      type: string
      id: string
      verdict: 'completed' | 'failed'
      evidence: string
    }) =>
      admin.post<Envelope<{ id: string; type: string; state: string }>>(
        `/operations/reinstalls/${encodeURIComponent(type)}/${encodeURIComponent(id)}/resolve`,
        { verdict, evidence },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'operations'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'provisioning'] })
    },
  })
}

/**
 * One disagreement between the platform and a provider.
 *
 * `expected` and `observed` are published deliberately: an operator deciding
 * whether a machine is really missing needs to see what the two sides said,
 * and both were redacted on the way into the table rather than here.
 */
export interface AdminDrift {
  id: string
  provider: string | null
  resource_type: string | null
  service_id: string | null
  provider_reference: string | null
  kind: string
  severity: string
  status: string
  expected: Record<string, unknown> | null
  observed: Record<string, unknown> | null
  occurrences: number
  first_seen_at: string | null
  last_seen_at: string | null
  resolution: string | null
  resolved_at: string | null
}

export function useDrift(page: number, status: string) {
  return useQuery({
    queryKey: ['admin', 'drift', page, status],
    queryFn: () => admin.get<Paginated<AdminDrift>>('/drift', { page, status }),
    refetchInterval: 30_000,
  })
}

export function useReviewDrift() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      id,
      verdict,
      resolution,
    }: {
      id: string
      verdict: 'acknowledged' | 'resolved'
      resolution?: string
    }) =>
      admin.post<Envelope<AdminDrift>>(`/drift/${encodeURIComponent(id)}/review`, {
        verdict,
        resolution,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'drift'] })
    },
  })
}

export function useReconcileCluster() {
  return useMutation({
    mutationFn: ({ clusterId }: { clusterId: string }) =>
      admin.post<Envelope<{ cluster_id: string; queued: boolean }>>(
        `/infrastructure/clusters/${encodeURIComponent(clusterId)}/reconcile`,
      ),
  })
}

export interface AdminNode {
  id: string
  name: string
  cluster: string | null
  cluster_id: string | null
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
