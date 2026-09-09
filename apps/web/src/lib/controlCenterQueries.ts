import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { admin } from '@/lib/adminQueries'
import type { Envelope, Paginated } from '@/lib/types'

/**
 * The Control Center's data layer.
 *
 * Three concerns — Infrastructure, Providers and Product Readiness — share
 * one navigation area and one query module, because a screen in one of them
 * routinely reads the others: a provider row shows the credential it uses and
 * the machine it runs on. What they do not share is a backend module, and the
 * paths here mirror that: `/credentials`, `/providers`, `/infrastructure/...`.
 */

export type Environment = 'development' | 'staging' | 'production'

export const ENVIRONMENTS: Environment[] = ['development', 'staging', 'production']

export interface Credential {
  id: string
  name: string
  purpose: string | null
  environment: Environment
  backend: string
  state: string
  usable: boolean
  /** Whether the deployment controller currently holds a value behind the reference. Never the value. */
  present: boolean
  masked_hint: string | null
  usage: { providers: number; servers: number }
  last_tested_at: string | null
  rotated_at: string | null
  rotates_at: string | null
  revoked_at: string | null
  revoked_reason: string | null
  notes: string | null
  created_at: string | null
}

export function useCredentials(page: number, environment?: Environment | '') {
  return useQuery({
    queryKey: ['admin', 'credentials', page, environment],
    queryFn: () => admin.get<Paginated<Credential>>('/credentials', { page, environment }),
  })
}

export interface RecordCredentialInput {
  name: string
  purpose: string
  environment: Environment
  /** The NAME of a variable on the deployment controller. Never the value. */
  backend_reference: string
  masked_hint?: string
  rotates_at?: string
  notes?: string
}

export function useRecordCredential() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RecordCredentialInput) =>
      admin.post<Envelope<Credential>>('/credentials', { backend: 'controller_environment', ...input }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'credentials'] })
    },
  })
}

export function useRevokeCredential() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      admin.post<Envelope<Credential>>(`/credentials/${encodeURIComponent(id)}/revoke`, { reason }),
    onSuccess: () => {
      // Revoking reassesses every provider using the credential, so their
      // rows are stale too.
      void queryClient.invalidateQueries({ queryKey: ['admin', 'credentials'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'providers'] })
    },
  })
}

export function useMarkCredentialRotated() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) =>
      admin.post<Envelope<Credential>>(`/credentials/${encodeURIComponent(id)}/rotated`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'credentials'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'providers'] })
    },
  })
}

/* -------------------------------------------------------------------------
 | Licences
 */

export interface Licence {
  id: string
  product: string
  licence_type: string | null
  environment: Environment
  state: string
  permits: boolean
  needs_attention: boolean
  days_remaining: number | null
  starts_on: string | null
  expires_on: string | null
  renews_on: string | null
  seats: number | null
  external_reference: string | null
  server: { id: string; server_name: string } | null
  credential: { id: string; credential_name: string; credential_state: string } | null
  usage: { providers: number }
  invalidated_at: string | null
  invalidated_reason: string | null
  renewed_at: string | null
  state_changed_at: string | null
  notes: string | null
  created_at: string | null
}

export function useLicences(page: number, environment?: Environment | '') {
  return useQuery({
    queryKey: ['admin', 'licences', page, environment],
    queryFn: () => admin.get<Paginated<Licence>>('/licences', { page, environment }),
  })
}

export interface RecordLicenceInput {
  product: string
  licence_type?: string
  environment: Environment
  starts_on?: string
  expires_on?: string
  renews_on?: string
  seats?: number
  external_reference?: string
  notes?: string
}

function invalidateLicenceViews(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['admin', 'licences'] })
  // Every licence change reassesses the providers under it.
  void queryClient.invalidateQueries({ queryKey: ['admin', 'providers'] })
}

export function useRecordLicence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RecordLicenceInput) => admin.post<Envelope<Licence>>('/licences', input),
    onSuccess: () => { invalidateLicenceViews(queryClient) },
  })
}

export function useRenewLicence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, expires_on, external_reference }: { id: string; expires_on: string; external_reference?: string }) =>
      admin.post<Envelope<Licence>>(`/licences/${encodeURIComponent(id)}/renew`, {
        expires_on,
        ...(external_reference === undefined ? {} : { external_reference }),
      }),
    onSuccess: () => { invalidateLicenceViews(queryClient) },
  })
}

export function useInvalidateLicence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      admin.post<Envelope<Licence>>(`/licences/${encodeURIComponent(id)}/invalidate`, { reason }),
    onSuccess: () => { invalidateLicenceViews(queryClient) },
  })
}

export interface LicenceSweep {
  examined: number
  changed: number
  providers_reassessed: number
}

export function useRefreshLicences() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => admin.post<Envelope<LicenceSweep>>('/licences/refresh', {}),
    onSuccess: () => { invalidateLicenceViews(queryClient) },
  })
}

/* -------------------------------------------------------------------------
 | Machines
 */

export type SafetyClass = 'do_not_touch' | 'discovery_only' | 'configuration_allowed' | 'reimage_allowed'

export const SAFETY_CLASSES: SafetyClass[] = ['do_not_touch', 'discovery_only', 'configuration_allowed', 'reimage_allowed']

export interface Server {
  id: string
  name: string
  environment: Environment
  state: string
  safety: {
    classification: SafetyClass
    allow_reimage: boolean
    reason: string | null
    changed_at: string | null
    permits: { read: boolean; configure: boolean; reimage: boolean }
  }
  location: { datacenter_id: string | null; rack_id: string | null; rack_unit: number | null; height_units: number | null }
  hardware: { vendor: string | null; model: string | null; serial: string | null; asset_tag: string | null; operating_system: string | null }
  connection: {
    state: string
    blocker: string | null
    management_address: string | null
    bmc_address: string | null
    credential?: { id: string; name: string; state: string } | null
    last_tested_at: string | null
  }
  last_discovery_at: string | null
  last_deployment_at: string | null
  last_verification_at: string | null
  notes: string | null
  created_at: string | null
}

export interface ServerFact {
  key: string
  value: string | null
  source: 'declared' | 'discovered' | 'derived'
  observed_at: string
  superseded_at: string | null
}

export function useServers(page: number, environment?: Environment | '') {
  return useQuery({
    queryKey: ['admin', 'servers', page, environment],
    queryFn: () => admin.get<Paginated<Server>>('/infrastructure/servers', { page, environment }),
  })
}

export function useServer(id: string | null) {
  return useQuery({
    queryKey: ['admin', 'servers', 'one', id],
    queryFn: () => admin.get<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id ?? '')}`),
    enabled: id !== null,
  })
}

export function useServerFacts(id: string | null) {
  return useQuery({
    queryKey: ['admin', 'servers', 'facts', id],
    queryFn: () => admin.get<{ data: ServerFact[] }>(`/infrastructure/servers/${encodeURIComponent(id ?? '')}/facts`),
    enabled: id !== null,
  })
}

export interface RegisterServerInput {
  name: string
  environment: Environment
  management_address?: string
  bmc_address?: string
  vendor?: string
  model?: string
  serial?: string
  notes?: string
}

function invalidateServerViews(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['admin', 'servers'] })
  // A machine's classification is a readiness input for the providers on it.
  void queryClient.invalidateQueries({ queryKey: ['admin', 'providers'] })
}

export function useRegisterServer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RegisterServerInput) => admin.post<Envelope<Server>>('/infrastructure/servers', input),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export function useClassifyServer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, safety_class, reason, confirm_name }: { id: string; safety_class: SafetyClass; reason: string; confirm_name?: string }) =>
      admin.post<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id)}/classify`, {
        safety_class,
        reason,
        ...(confirm_name === undefined ? {} : { confirm_name }),
      }),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export function useClearForReimage() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, confirm_name, reason }: { id: string; confirm_name: string; reason: string }) =>
      admin.post<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id)}/clear-for-reimage`, { confirm_name, reason }),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export function useRevokeReimageClearance() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) =>
      admin.delete<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id)}/clear-for-reimage`),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export function useAttachServerCredential() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, credential_id }: { id: string; credential_id: string | null }) =>
      credential_id === null
        ? admin.delete<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id)}/credential`)
        : admin.post<Envelope<Server>>(`/infrastructure/servers/${encodeURIComponent(id)}/credential`, { credential_id }),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export interface ConnectionTestResult {
  id: string
  result: string
  reached: boolean
  usable: boolean
  blocker: string | null
  next_action: string | null
  steps: Array<{ name: string; outcome: string; detail?: string }>
  detail: string | null
  tested_at: string | null
}

export function useTestServerConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) =>
      admin.post<Envelope<ConnectionTestResult>>(`/infrastructure/servers/${encodeURIComponent(id)}/connection-test`, {}),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

export interface ServerDiscovery {
  result: string
  usable: boolean
  facts: number
  server: Server
}

export function useDiscoverServer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) =>
      admin.post<Envelope<ServerDiscovery>>(`/infrastructure/servers/${encodeURIComponent(id)}/discover`, {}),
    onSuccess: () => { invalidateServerViews(queryClient) },
  })
}

/* -------------------------------------------------------------------------
 | Providers and the catalogue
 */

export interface CatalogueEntry {
  driver: string
  category: string
  summary: string
  needs_endpoint: boolean
  needs_credential: boolean
  needs_licence: boolean
  needs_server: boolean
  testable: boolean
  available_here: boolean
}

export function useCatalogue() {
  return useQuery({
    queryKey: ['admin', 'providers', 'catalogue'],
    queryFn: () => admin.get<{ data: CatalogueEntry[] }>('/providers/catalogue'),
    staleTime: 5 * 60_000,
  })
}

export interface ProviderCapability {
  capability: string
  capability_state: string
  observed_at: string | null
}

export interface Provider {
  id: string
  name: string
  category: string
  driver: string
  environment: Environment
  state: string
  is_serving: boolean
  can_test: boolean
  endpoint: string | null
  connection: { state: string; reached: boolean; usable: boolean; detail: string | null; last_tested_at: string | null; last_discovery_at: string | null }
  readiness: { state: string; blocker: string | null; next_action: string | null }
  credential?: { id: string; credential_name: string; credential_state: string; credential_environment: string } | null
  licence?: { id: string; product: string; licence_state: string; expires_on: string | null } | null
  server?: { id: string; server_name: string; classification: string } | null
  capabilities?: ProviderCapability[]
  enabled_at: string | null
  disabled_at: string | null
  disabled_reason: string | null
  created_at: string | null
}

export function useProviders(page: number, filters: { environment?: Environment | ''; category?: string } = {}) {
  return useQuery({
    queryKey: ['admin', 'providers', page, filters.environment, filters.category],
    queryFn: () => admin.get<Paginated<Provider>>('/providers', { page, environment: filters.environment, category: filters.category }),
  })
}

export interface RegisterProviderInput {
  name: string
  driver: string
  category: string
  environment: Environment
  endpoint?: string
  managed_server_id?: string
  notes?: string
}

function invalidateProviderViews(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['admin', 'providers'] })
  void queryClient.invalidateQueries({ queryKey: ['admin', 'credentials'] })
  void queryClient.invalidateQueries({ queryKey: ['admin', 'licences'] })
}

export function useRegisterProvider() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RegisterProviderInput) => admin.post<Envelope<Provider>>('/providers', input),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

export function useEnableProvider() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) => admin.post<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/enable`, {}),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

export function useDisableProvider() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      admin.post<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/disable`, { reason }),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

export function useAssessProvider() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) => admin.post<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/assess`, {}),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

/** One round trip that proves the credential and discovers what the account can do. */
export function useTestProviderConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id }: { id: string }) =>
      admin.post<Envelope<ConnectionTestResult>>(`/providers/${encodeURIComponent(id)}/connection-test`, {}),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

export function useAttachProviderCredential() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, credential_id }: { id: string; credential_id: string | null }) =>
      credential_id === null
        ? admin.delete<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/credential`)
        : admin.post<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/credential`, { credential_id }),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

export function useAttachProviderLicence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, licence_id }: { id: string; licence_id: string | null }) =>
      licence_id === null
        ? admin.delete<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/licence`)
        : admin.post<Envelope<Provider>>(`/providers/${encodeURIComponent(id)}/licence`, { licence_id }),
    onSuccess: () => { invalidateProviderViews(queryClient) },
  })
}

/* -------------------------------------------------------------------------
 | Product readiness
 */

export type ProductName = 'vps' | 'dedicated' | 'shared_hosting' | 'wordpress' | 'domains' | 'dns' | 'backups'

export type ProductReadinessState = 'not_ready' | 'ready_for_test' | 'ready_for_real_validation' | 'ready_for_production' | 'ready_to_sell'

export const READINESS_LADDER: ProductReadinessState[] = ['not_ready', 'ready_for_test', 'ready_for_real_validation', 'ready_for_production', 'ready_to_sell']

export interface RequirementRow {
  category: string
  capabilities: string[]
  shared: boolean
  satisfied_up_to: ProductReadinessState
  provider_id: string | null
  provider_name: string | null
  blocker: string | null
  next_action: string | null
  detail: string
}

export interface ProductReadiness {
  product: ProductName
  state: ProductReadinessState
  next_state: ProductReadinessState | null
  blocker: string | null
  next_action: string | null
  detail: string | null
  depends_on: ProductName[]
  dependencies: Record<string, ProductReadinessState>
  requirements: RequirementRow[]
  sellable: {
    declared: boolean
    declared_at: string | null
    reason: string | null
    validation_reference: string | null
    withdrawn_at: string | null
    withdrawn_reason: string | null
  }
  assessed_at: string | null
  state_changed_at: string | null
}

export interface ProductDependency {
  product: ProductName
  state: ProductReadinessState
  depends_on: ProductName[]
  providers: Array<{ category: string; shared: boolean; provider_id: string | null; provider_name: string | null; satisfied_up_to: ProductReadinessState }>
}

export interface ReadinessSweep {
  examined: number
  changed: number
  products: Record<string, ProductReadinessState>
}

function invalidateReadinessViews(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['admin', 'readiness'] })
}

export function useProductReadiness() {
  return useQuery({
    queryKey: ['admin', 'readiness', 'products'],
    queryFn: () => admin.get<{ data: ProductReadiness[] }>('/readiness/products'),
  })
}

export function useProductDependencies() {
  return useQuery({
    queryKey: ['admin', 'readiness', 'dependencies'],
    queryFn: () => admin.get<{ data: ProductDependency[] }>('/readiness/dependencies'),
  })
}

export function useAssessAllProducts() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => admin.post<Envelope<ReadinessSweep>>('/readiness/products/assess', {}),
    onSuccess: () => { invalidateReadinessViews(queryClient) },
  })
}

export function useDeclareSellable() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ product, reason, validation_reference }: { product: ProductName; reason: string; validation_reference: string }) =>
      admin.post<Envelope<ProductReadiness>>(`/readiness/products/${encodeURIComponent(product)}/sellable`, { reason, validation_reference }),
    onSuccess: () => { invalidateReadinessViews(queryClient) },
  })
}

export function useWithdrawSellability() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ product, reason }: { product: ProductName; reason: string }) =>
      admin.delete<Envelope<ProductReadiness>>(`/readiness/products/${encodeURIComponent(product)}/sellable`, { reason }),
    onSuccess: () => { invalidateReadinessViews(queryClient) },
  })
}
