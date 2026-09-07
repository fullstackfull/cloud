import { describe, expect, it } from 'vitest'

import ar from '../locales/ar.json'
import en from '../locales/en.json'

type Tree = { [key: string]: string | Tree }

function flatten(tree: Tree, prefix = ''): Map<string, string> {
  const flat = new Map<string, string>()

  for (const [key, value] of Object.entries(tree)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') {
      flat.set(path, value)
    } else {
      for (const [nested, nestedValue] of flatten(value, path)) {
        flat.set(nested, nestedValue)
      }
    }
  }

  return flat
}

/**
 * The plural suffixes i18next appends to a key.
 *
 * English needs two forms and Arabic needs six, so a key that is pluralised
 * cannot have the same set of variants in both catalogues. Comparing the base
 * key is the comparison that means something; comparing `planCount_two` across
 * languages would demand that English grow a dual.
 */
const PLURAL_SUFFIX = /_(zero|one|two|few|many|other)$/

function baseKey(key: string): string {
  return key.replace(PLURAL_SUFFIX, '')
}

/** Interpolation placeholders, e.g. {{name}}. */
function placeholdersIn(value: string): string[] {
  return [...value.matchAll(/\{\{(\w+)\}\}/g)].map((match) => match[1] ?? '').sort()
}

describe('translation catalogues', () => {
  const english = flatten(en)
  const arabic = flatten(ar)

  const englishBases = new Set([...english.keys()].map(baseKey))
  const arabicBases = new Set([...arabic.keys()].map(baseKey))

  it('covers every English key in Arabic', () => {
    // A missing key does not throw at runtime — i18next falls back to English —
    // so an Arabic customer would silently get an English sentence in the middle
    // of an Arabic page. The only place that can be caught is here.
    const missing = [...englishBases].filter((key) => !arabicBases.has(key))
    expect(missing).toEqual([])
  })

  it('has no Arabic key that English lacks', () => {
    const orphaned = [...arabicBases].filter((key) => !englishBases.has(key))
    expect(orphaned).toEqual([])
  })

  it('gives every pluralised key the forms its language needs', () => {
    // Arabic selects among six forms and English among two. A pluralised key
    // that carries only `_other` in Arabic renders "2 خطة" where the language
    // wants "خطتان" — grammatically wrong, and invisible to anyone reading the
    // English side.
    const pluralised = [...englishBases].filter((base) =>
      [...english.keys()].some((key) => key !== base && baseKey(key) === base),
    )

    const incomplete = pluralised.filter((base) => {
      const forms = [...arabic.keys()].filter((key) => baseKey(key) === base)
      return !['zero', 'one', 'two', 'few', 'many', 'other'].every((suffix) =>
        forms.includes(`${base}_${suffix}`),
      )
    })

    expect(incomplete).toEqual([])
  })

  it('uses the same interpolation placeholders in both languages', () => {
    // A translated string that drops {{name}} renders a sentence with a hole in
    // it, and one that invents a placeholder renders the braces literally.
    /*
     * Compared per exact key, and only where both catalogues have that exact
     * key. A plural form that legitimately omits the number — "خطة واحدة"
     * reads better than "1 خطة" — is not a dropped placeholder, so the
     * comparison skips forms English does not itself have.
     */
    const mismatched = [...english.entries()]
      .filter(([key, value]) => {
        const translated = arabic.get(key)
        if (translated === undefined) return false
        if (PLURAL_SUFFIX.test(key)) return false
        return placeholdersIn(value).join(',') !== placeholdersIn(translated).join(',')
      })
      .map(([key]) => key)

    expect(mismatched).toEqual([])
  })

  it('has no empty translation', () => {
    const empty = [...arabic.entries()]
      .concat([...english.entries()])
      .filter(([, value]) => value.trim() === '')
      .map(([key]) => key)

    expect(empty).toEqual([])
  })
})
