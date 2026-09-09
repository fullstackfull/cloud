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
