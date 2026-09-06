import { describe, expect, it } from 'vitest'

import ar from '../locales/ar.json'
import en from '../locales/en.json'
import { directionFor, isSupportedLocale, SUPPORTED_LOCALES } from '../index'

type Tree = { [key: string]: string | Tree }

/**
 * Returns [path, value] pairs rather than paths alone.
 *
 * Some keys legitimately contain a dot — the API error codes are named
 * `auth.invalid_credentials` and so on — so a path string cannot be split back
 * into a lookup. Carrying the value avoids the ambiguity entirely.
 */
function flatten(tree: Tree, prefix = ''): Array<[string, string]> {
  return Object.entries(tree).flatMap(([key, value]): Array<[string, string]> => {
    const path = prefix === '' ? key : `${prefix}.${key}`
    return typeof value === 'string' ? [[path, value]] : flatten(value, path)
  })
}

function pathsOf(tree: Tree): string[] {
  return flatten(tree).map(([path]) => path)
}

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
  it('define exactly the same keys', () => {
    // A missing Arabic key renders an English string mid-sentence, which is
    // both a visible defect and a direction bug.
    const englishKeys = pathsOf(en).sort()
    const arabicKeys = pathsOf(ar).sort()

    expect(arabicKeys).toEqual(englishKeys)
  })

  it('has no empty translations', () => {
    for (const locale of SUPPORTED_LOCALES) {
      const catalogue = locale === 'ar' ? ar : en
      for (const [path, value] of flatten(catalogue)) {
        expect(value.trim(), `${locale}: ${path}`).not.toBe('')
      }
    }
  })

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
