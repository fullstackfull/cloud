import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { ApiError, api } from '@/lib/api'

export interface AuthenticatedUser {
  id: string
  name: string
  email: string
  email_verified: boolean
  locale: string
  timezone: string
  phone: string | null
  two_factor_enabled: boolean
  last_login_at: string | null
  created_at: string | null
  permissions: string[]
  customers: CustomerSummary[]
}

export interface CustomerSummary {
  id: string
  type: 'individual' | 'organization'
  status: 'active' | 'suspended' | 'closed'
  display_name: string
  legal_name: string | null
  currency: string
  country: string | null
  can_purchase: boolean
  role?: string | null
}

interface Envelope<T> {
  data: T
}

export const AUTH_QUERY_KEY = ['auth', 'me'] as const

export function useCurrentUser() {
  return useQuery({
    queryKey: AUTH_QUERY_KEY,
    queryFn: async (): Promise<AuthenticatedUser | null> => {
      try {
        const response = await api.get<Envelope<AuthenticatedUser>>('/me')
        return response.data
      } catch (error) {
        // Not being signed in is an expected state on first load, not a
        // failure to report.
        if (error instanceof ApiError && error.isUnauthenticated) return null
        throw error
      }
    },
    staleTime: 60_000,
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status < 500) return false
      return failureCount < 2
    },
  })
}

export interface LoginCredentials {
  email: string
  password: string
  remember?: boolean
}

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (credentials: LoginCredentials): Promise<AuthenticatedUser> => {
      const response = await api.post<Envelope<AuthenticatedUser>>('/login', credentials)
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(AUTH_QUERY_KEY, user)
    },
  })
}

export interface TwoFactorChallenge {
  challenge_token: string
  code: string
}

export function useTwoFactorChallenge() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (challenge: TwoFactorChallenge): Promise<AuthenticatedUser> => {
      const response = await api.post<Envelope<AuthenticatedUser>>('/login/two-factor', challenge)
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(AUTH_QUERY_KEY, user)
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => api.post<null>('/logout'),
    onSettled: () => {
      // Cleared even when the request fails: the local view of "signed in"
      // must not outlive the user's intent to leave.
      queryClient.setQueryData(AUTH_QUERY_KEY, null)
      void queryClient.invalidateQueries()
    },
  })
}

/**
 * Reads the two-factor challenge token out of the error the login endpoint
 * returns when a second factor is required.
 */
export function challengeTokenFrom(error: unknown): string | null {
  if (!(error instanceof ApiError) || error.code !== 'auth.two_factor_required') return null

  const token = error.details['challenge_token']
  return typeof token === 'string' ? token : null
}
