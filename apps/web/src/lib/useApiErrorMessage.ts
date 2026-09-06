import { useTranslation } from 'react-i18next'

import { ApiError, NetworkError } from '@/lib/api'

export interface DisplayableError {
  message: string
  requestId: string | undefined
  fields: Record<string, string[]> | null
}

/**
 * Turns any thrown value into something a person can act on.
 *
 * API errors carry a stable code, so the message shown is a translated string
 * chosen by that code rather than the server's English prose — which keeps the
 * Arabic UI fully Arabic even when the failure originates server-side.
 */
export function useApiErrorMessage(): (error: unknown) => DisplayableError | null {
  const { t } = useTranslation()

  return (error: unknown): DisplayableError | null => {
    if (error === null || error === undefined) return null

    if (error instanceof NetworkError) {
      return { message: t('errors.network'), requestId: undefined, fields: null }
    }

    if (error instanceof ApiError) {
      const key = `errors.${error.code}`
      const translated = t(key)

      return {
        message: error.isRateLimited
          ? t('errors.rateLimited')
          : // Fall back to the server's message when the code has no
            // translation yet, rather than showing the raw key.
            translated === key
            ? error.message
            : translated,
        requestId: error.requestId,
        fields: error.fields,
      }
    }

    return { message: t('errors.server.error'), requestId: undefined, fields: null }
  }
}
