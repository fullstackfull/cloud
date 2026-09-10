import { afterEach, describe, expect, it, vi } from 'vitest'

import i18n, { changeLocale } from '@/i18n'
import { acceptLanguage, request } from '@/lib/api'

/**
 * Every request carries the active language, from the shared client and
 * nowhere else, and a language switch changes the very next request.
 */
describe('Accept-Language', () => {
  afterEach(async () => {
    vi.restoreAllMocks()
    await i18n.changeLanguage('en')
  })

  function captureHeaders(): () => Headers {
    let captured = new Headers()
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      if (!(input instanceof Request) && String(input).endsWith('/sanctum/csrf-cookie')) {
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      captured = new Headers(init?.headers)
      return Promise.resolve(new Response('{"data":[]}', { status: 200 }))
    })
    return () => captured
  }

  it('sends the active language on every request', async () => {
    const headers = captureHeaders()

    await request('/vps')
    expect(headers().get('Accept-Language')).toBe('en')

    await changeLocale('ar')
    await request('/vps')
    expect(headers().get('Accept-Language')).toBe('ar')
    expect(document.documentElement.dir).toBe('rtl')
  })

  it('narrows a regional or unknown language to one the platform serves', async () => {
    await i18n.changeLanguage('ar-KW')
    expect(acceptLanguage()).toBe('ar')

    await i18n.changeLanguage('de')
    expect(acceptLanguage()).toBe('en')
  })

  it('lets a caller override the language for one request without changing the default', async () => {
    const headers = captureHeaders()

    await request('/vps', { locale: 'ar' })
    expect(headers().get('Accept-Language')).toBe('ar')

    await request('/vps')
    expect(headers().get('Accept-Language')).toBe('en')
  })
})
