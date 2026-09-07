import { describe, expect, it } from 'vitest'

import ar from '../locales/ar.json'
import en from '../locales/en.json'
import { directionFor, isSupportedLocale } from '../index'

describe('locale configuration', () => {
  it('maps Arabic to right-to-left and English to left-to-right', () => {
    expect(directionFor('ar')).toBe('rtl')
    expect(directionFor('en')).toBe('ltr')
  })

  it('rejects an unsupported locale', () => {
    expect(isSupportedLocale('en')).toBe(true)
    expect(isSupportedLocale('fr')).toBe(false)
  })
})

describe('translation catalogues', () => {
  /*
   * Key parity, placeholder parity and plural completeness now live in
   * translation-parity.test.ts, which understands that i18next appends a
   * plural suffix and that Arabic needs six forms where English needs two.
   * The versions that were here compared raw key sets and could not: they
   * failed the moment a key was correctly pluralised.
   */

  it('translates every API error code the client can surface', () => {
    const required = [
      'auth.invalid_credentials',
      'auth.account_locked',
      'auth.two_factor_required',
      'auth.unauthenticated',
      'auth.forbidden',
      'validation.failed',
      'resource.not_found',
      'server.error',
    ]

    for (const code of required) {
      expect(Object.keys(en.errors)).toContain(code)
      expect(Object.keys(ar.errors)).toContain(code)
    }
  })
})
