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
 * The order is deliberate and is what keeps an Arabic page Arabic:
 *
 *  1. A sentence the portal has for this code (`errors.<code>`), in the
 *     active language. Kept for the conditions only the client can see —
 *     network, rate limiting — and for the few codes whose portal wording
 *     differs from the API's.
 *  2. The API's own sentence, when the refusal is the customer's (a 4xx). The
 *     API composes it from the customer error catalogue in the language this
 *     request asked for (`Accept-Language`), so it is already translated and
 *     already safe; the portal shows it rather than duplicating two hundred
 *     sentences in a second catalogue.
 *  3. A generic sentence with the request reference, for everything else: a
 *     5xx, a response with no body, a code the catalogue does not know. What
 *     the server said in those cases is for the log, not the screen.
 *
 * Nothing here matches on English prose. A code decides the branch, and the
 * text on screen is either the portal's catalogue or the API's.
 */
export function useApiErrorMessage(): (error: unknown) => DisplayableError | null {
  const { t } = useTranslation()

  return (error: unknown): DisplayableError | null => {
    if (error === null || error === undefined) return null

    if (error instanceof NetworkError) {
      return { message: t('errors.network'), requestId: undefined, fields: null }
    }

    if (error instanceof ApiError) {
      if (error.isRateLimited) {
        return { message: t('errors.rateLimited'), requestId: error.requestId, fields: error.fields }
      }

      const key = `errors.${error.code}`
      const translated = t(key)

      return {
        message:
          translated !== key
            ? translated
            : error.status < 500 && error.message.trim() !== '' && error.message !== error.code
              ? error.message
              : t('errors.server.error'),
        requestId: error.requestId,
        fields: error.fields,
      }
    }

    return { message: t('errors.server.error'), requestId: undefined, fields: null }
  }
}
