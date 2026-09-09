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
