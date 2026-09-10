import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import i18n from '@/i18n'
import en from '@/i18n/locales/en.json'
import { ApiError, NetworkError } from '@/lib/api'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * One safe path from any failure to a sentence a customer can read: the
 * portal's own translation by code, else the API's localised sentence for a
 * customer refusal, else a generic sentence with the reference — and never
 * the server's words for a 5xx.
 */
describe('useApiErrorMessage', () => {
  function describeError(error: unknown) {
    const { result } = renderHook(() => useApiErrorMessage())
    return result.current(error)
  }

  it('prefers the portal translation for a code it knows', () => {
    const shown = describeError(new ApiError(422, { code: 'validation.failed', message: 'The submitted data is invalid.' }))
    expect(shown?.message).toBe(en.errors['validation.failed'])
  })

  it('shows the API sentence for a customer refusal it has no translation of, because the API localised it', () => {
    const shown = describeError(
      new ApiError(409, { code: 'vps.operation_in_flight', message: 'توجد عملية أخرى قيد التنفيذ.', request_id: 'req-1' }),
    )
    expect(shown?.message).toBe('توجد عملية أخرى قيد التنفيذ.')
    expect(shown?.requestId).toBe('req-1')
  })

  it('never shows the server words for a 5xx, only the generic sentence and the reference', () => {
    const shown = describeError(
      new ApiError(500, { code: 'server.error', message: 'PDOException at pve-node-03: connection refused', request_id: 'req-2' }),
    )
    expect(shown?.message).toBe(en.errors['server.error'])
    expect(shown?.message).not.toMatch(/pve-node|PDOException/)
    expect(shown?.requestId).toBe('req-2')

    const gateway = describeError(new ApiError(502, { code: 'http.502', message: 'Bad Gateway' }))
    expect(gateway?.message).toBe(en.errors['server.error'])
  })

  it('falls back to the generic sentence when a 4xx carries no usable message', () => {
    const shown = describeError(new ApiError(400, { code: 'http.400', message: '' }))
    expect(shown?.message).toBe(en.errors['server.error'])
  })

  it('keeps the client-only conditions in the portal catalogue', () => {
    expect(describeError(new NetworkError(new Error('offline')))?.message).toBe(en.errors.network)
    expect(describeError(new ApiError(429, { code: 'http.429', message: 'x' }))?.message).toBe(en.errors.rateLimited)
    expect(describeError(new Error('boom'))?.message).toBe(en.errors['server.error'])
  })

  it('answers in Arabic when the portal is Arabic', async () => {
    await i18n.changeLanguage('ar')
    try {
      const shown = describeError(new ApiError(500, { code: 'server.error', message: 'stack trace' }))
      expect(shown?.message).toMatch(/\p{Script=Arabic}/u)
    } finally {
      await i18n.changeLanguage('en')
    }
  })
})
