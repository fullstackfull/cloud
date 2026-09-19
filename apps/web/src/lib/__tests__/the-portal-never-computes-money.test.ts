import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { countryName, sortByCountryName } from '@/lib/countryNames'

/**
 * A gate, not a unit test: nothing in this portal may do arithmetic on money.
 *
 * Every amount the API sends is minor units plus an ISO-4217 code, and the
 * portal's whole job with it is formatting. The moment a screen multiplies a
 * unit price by a quantity, or reads `.amount` as a float to add a tax, it has
 * a second implementation of pricing — and it will agree with the invoice until
 * it does not, at which point the customer is right and the platform is wrong.
 *
 * So the patterns that would start that are searched for in the source. The
 * list is deliberately narrow: it catches the float conversions and the
 * arithmetic on money-shaped names, and it does not try to be a type checker.
 */

const SOURCE_ROOT = path.resolve(import.meta.dirname, '../..')

/** Files that are allowed to talk about money in these terms. */
const ALLOWED = new Set([
  // The formatter itself, which is the one place a minor-unit integer is
  // turned into a string for a reader.
  'lib/format.ts',
])

interface Offence {
  file: string
  line: number
  text: string
  why: string
}

const FORBIDDEN: Array<{ pattern: RegExp; why: string }> = [
  { pattern: /parseFloat\s*\(/, why: 'parseFloat on an amount turns money into a float' },
  {
    pattern: /Number\s*\(\s*[A-Za-z_$][\w.$]*(?:amount|price|total|minor_units|balance)/i,
    why: 'Number() on an amount turns money into a float',
  },
  {
    pattern: /(?:amount|price|total|minor_units|balance|recurring|setup|subtotal|tax|discount)\w*\s*[*/]\s*(?!100\b)/i,
    why: 'arithmetic on an amount is pricing, and pricing belongs to the server',
  },
  {
    pattern: /(?:minor_units|amount)\s*\+\s*(?!'|")/,
    why: 'adding amounts in the browser is a second implementation of a total',
  },
]

/**
 * Every source file under src/, walked with node's own directory reader so
 * this gate depends on nothing that has to be installed.
 */
function sourceFiles(directory = ''): string[] {
  const entries = readdirSync(path.join(SOURCE_ROOT, directory), { withFileTypes: true })

  return entries.flatMap((entry) => {
    const relative = directory === '' ? entry.name : `${directory}/${entry.name}`

    if (entry.isDirectory()) {
      return entry.name === '__tests__' ? [] : sourceFiles(relative)
    }

    return /\.tsx?$/.test(entry.name) && !entry.name.endsWith('.d.ts') ? [relative] : []
  })
}

function offences(): Offence[] {
  const files = sourceFiles()

  const found: Offence[] = []

  for (const relative of files) {
    if (ALLOWED.has(relative)) continue

    const source = readFileSync(path.join(SOURCE_ROOT, relative), 'utf8')

    source.split('\n').forEach((text, index) => {
      const code = text.trim()

      // Comments discuss money arithmetic constantly; they are prose.
      if (code.startsWith('//') || code.startsWith('*') || code.startsWith('/*')) return

      for (const { pattern, why } of FORBIDDEN) {
        if (pattern.test(text)) {
          found.push({ file: relative, line: index + 1, text: code, why })
        }
      }
    })
  }

  return found
}

describe('the portal never computes money', () => {
  it('recognises the arithmetic it is looking for', () => {
    /*
     * A gate that cannot fire proves nothing. These are the lines this test
     * exists to reject, and they must match.
     */
    const wouldBeRejected = [
      'const total = parseFloat(invoice.total.amount)',
      'const minor = Number(price.amount)',
      'const due = line.unit_amount.minor_units * quantity',
      'const sum = first.minor_units + second.minor_units',
    ]

    for (const line of wouldBeRejected) {
      expect(FORBIDDEN.some(({ pattern }) => pattern.test(line))).toBe(true)
    }

    // And these are ordinary code that must not be caught.
    const wouldBeAllowed = [
      'const label = t(`billingPeriod.${price.billing_period}`)',
      'const page = pageNumber + 1',
      "const text = 'amount' + suffix",
    ]

    for (const line of wouldBeAllowed) {
      expect(FORBIDDEN.some(({ pattern }) => pattern.test(line))).toBe(false)
    }
  })

  it('does no arithmetic on an amount anywhere in the source', () => {
    const found = offences()

    expect(
      found.map((offence) => `${offence.file}:${offence.line} — ${offence.why}: ${offence.text}`),
    ).toEqual([])
  })
})

describe('country names come from the browser', () => {
  it('names a country in the reader’s language', () => {
    expect(countryName('KW', 'en')).toBe('Kuwait')
    expect(countryName('SA', 'ar')).toContain('السعودية')
  })

  it('falls back to the code rather than showing nothing', () => {
    // Not a region code at all: the answer is the input, which is still
    // something a customer can recognise.
    expect(countryName('ZZZZ', 'en')).toBe('ZZZZ')
  })

  it('sorts by the name a reader sees, not by the code', () => {
    const sorted = sortByCountryName([{ code: 'ZA' }, { code: 'KW' }, { code: 'AE' }], 'en')

    // Kuwait, South Africa, United Arab Emirates — alphabetical by name, which
    // is not the order the codes are in.
    expect(sorted.map((row) => row.code)).toEqual(['KW', 'ZA', 'AE'])
  })
})
