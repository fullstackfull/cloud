import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { AUTH_QUERY_KEY, type AuthenticatedUser } from '@/features/auth/useAuth'
import { api } from '@/lib/api'

interface Envelope<T> {
  data: T
}

export interface ProfileChanges {
  name?: string
  locale?: string
  timezone?: string
  phone?: string | null
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (changes: ProfileChanges): Promise<AuthenticatedUser> => {
      const response = await api.patch<Envelope<AuthenticatedUser>>('/me', changes)
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(AUTH_QUERY_KEY, user)
    },
  })
}

export interface PasswordChange {
  current_password: string
  password: string
  password_confirmation: string
}

export function useChangePassword() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (change: PasswordChange) => api.put<null>('/me/password', change),
    onSuccess: () => {
      /*
       * Changing the password revokes every other session and every API token
       * server-side. The sessions list on screen is therefore stale the moment
       * this returns, and showing a revoked session as active would misreport
       * the security state of the account.
       */
      void queryClient.invalidateQueries({ queryKey: SESSIONS_QUERY_KEY })
      void queryClient.invalidateQueries({ queryKey: LOGIN_ACTIVITY_QUERY_KEY })
    },
  })
}

export interface ActiveSession {
  id: string
  ip_address: string | null
  user_agent: string | null
  last_active_at: string
  is_current: boolean
}

export const SESSIONS_QUERY_KEY = ['account', 'sessions'] as const

export function useSessions() {
  return useQuery({
    queryKey: SESSIONS_QUERY_KEY,
    queryFn: async (): Promise<ActiveSession[]> => {
      const response = await api.get<Envelope<ActiveSession[]>>('/me/sessions')
      return response.data
    },
  })
}

export function useRevokeSession() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (sessionId: string) => api.delete<null>(`/me/sessions/${encodeURIComponent(sessionId)}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: SESSIONS_QUERY_KEY })
    },
  })
}

export function useRevokeOtherSessions() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => api.delete<null>('/me/sessions'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: SESSIONS_QUERY_KEY })
    },
  })
}

/**
 * The outcomes the server records. Typed as the open string it is on the wire:
 * a closed union here would be a lie the moment the server learns a new one,
 * and the UI already falls back to rendering an unrecognised value verbatim.
 */
export type LoginOutcome = string

/** The outcomes the portal has a translation and a tone for. */
export const SUCCESSFUL_LOGIN_OUTCOMES: readonly string[] = ['success', 'token_issued']

export interface LoginActivityEntry {
  id: string
  outcome: LoginOutcome
  ip_address: string | null
  user_agent: string | null
  country: string | null
  occurred_at: string | null
}

export const LOGIN_ACTIVITY_QUERY_KEY = ['account', 'login-activity'] as const

export function useLoginActivity() {
  return useQuery({
    queryKey: LOGIN_ACTIVITY_QUERY_KEY,
    queryFn: async (): Promise<LoginActivityEntry[]> => {
      const response = await api.get<Envelope<LoginActivityEntry[]>>('/me/login-activity')
      return response.data
    },
  })
}
