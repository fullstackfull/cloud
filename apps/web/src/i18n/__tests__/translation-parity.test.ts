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

/** Interpolation placeholders, e.g. {{name}}. */
function placeholdersIn(value: string): string[] {
  return [...value.matchAll(/\{\{(\w+)\}\}/g)].map((match) => match[1] ?? '').sort()
}

describe('translation catalogues', () => {
  const english = flatten(en)
  const arabic = flatten(ar)

  it('covers every English key in Arabic', () => {
    // A missing key does not throw at runtime — i18next falls back to English —
    // so an Arabic customer would silently get an English sentence in the middle
    // of an Arabic page. The only place that can be caught is here.
    const missing = [...english.keys()].filter((key) => !arabic.has(key))
    expect(missing).toEqual([])
  })

  it('has no Arabic key that English lacks', () => {
    const orphaned = [...arabic.keys()].filter((key) => !english.has(key))
    expect(orphaned).toEqual([])
  })

  it('uses the same interpolation placeholders in both languages', () => {
    // A translated string that drops {{name}} renders a sentence with a hole in
    // it, and one that invents a placeholder renders the braces literally.
    const mismatched = [...english.entries()]
      .filter(([key, value]) => {
        const translated = arabic.get(key)
        if (translated === undefined) return false
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
