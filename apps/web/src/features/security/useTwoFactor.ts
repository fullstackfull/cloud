import { useMutation, useQueryClient } from '@tanstack/react-query'

import { AUTH_QUERY_KEY } from '@/features/auth/useAuth'
import { api } from '@/lib/api'

interface Envelope<T> {
  data: T
}

export interface TwoFactorEnrolment {
  /** The shared secret, for a customer whose authenticator cannot scan a code. */
  secret: string
  /** otpauth:// URI the authenticator app consumes. */
  otpauth_url: string
}

/**
 * Begins enrolment: the server generates a secret and returns it once.
 *
 * Enrolment is not complete until a code from the authenticator is confirmed,
 * so a customer who abandons this step is left exactly as they were rather than
 * locked out of an account whose second factor was never set up.
 */
export function useBeginTwoFactorEnrolment() {
  return useMutation({
    mutationFn: async (): Promise<TwoFactorEnrolment> => {
      const response = await api.post<Envelope<TwoFactorEnrolment>>('/me/two-factor')
      return response.data
    },
  })
}

export function useConfirmTwoFactor() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (code: string): Promise<string[]> => {
      const response = await api.post<Envelope<{ recovery_codes: string[] }>>(
        '/me/two-factor/confirm',
        { code },
      )
      return response.data.recovery_codes
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: AUTH_QUERY_KEY })
    },
  })
}

export function useDisableTwoFactor() {
  const queryClient = useQueryClient()

  return useMutation({
    // The current password is required even inside an authenticated session:
    // a hijacked session must not be able to remove the control that would
    // have stopped the hijack.
    mutationFn: (currentPassword: string) =>
      api.delete<null>('/me/two-factor', { current_password: currentPassword }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: AUTH_QUERY_KEY })
    },
  })
}

export function useRegenerateRecoveryCodes() {
  return useMutation({
    mutationFn: async (currentPassword: string): Promise<string[]> => {
      const response = await api.post<Envelope<{ recovery_codes: string[] }>>(
        '/me/two-factor/recovery-codes',
        { current_password: currentPassword },
      )
      return response.data.recovery_codes
    },
  })
}
