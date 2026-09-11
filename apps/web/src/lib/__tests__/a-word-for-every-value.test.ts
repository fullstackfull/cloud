import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import '@/i18n'
import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'
import { fallbackKey, hasLabel, labelledNamespaces, safeLabel } from '@/lib/safeLabel'

/**
 * §34. No raw enum, no key path, no provider sentence on a customer screen.
 *
 * The portal renders about thirty values the server chose, through `t()` with
 * a key built at runtime. Every one of them used to end `{ defaultValue:
 * theValue }`, and that fallback is the defect: when the namespace does not
 * cover the value, the customer reads the value. `ded-standard-1` on a
 * dedicated server's page. `needs_review` prettified to "needs review" in a
 * badge. An English slug on an Arabic page, styled as a label.
 *
 * Removing the fallback is worse — i18next returns the key on a miss, so the
 * page would read `dedicated.profile.ded-standard-1` instead.
 *
 * These tests hold the third answer in place: one helper that returns a
 * written, translated sentence either way, and a source gate so the old
 * pattern cannot come back.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

/**
 * Where customer screens live. The operator area is excluded for the same
 * reason the backend catalogue gate excludes it: staff read English, and
 * translating a drift kind would be translating a log.
 */
const CUSTOMER_DIRECTORIES = [
  'app',
  'components',
  'features/account',
  'features/activity',
  'features/auth',
  'features/backups',
  'features/billing',
  'features/catalog',
  'features/console',
  'features/dns',
  'features/domains',
  'features/infrastructure',
  'features/notifications',
  'features/operations',
  'features/orders',
  'features/payments',
  'features/resources',
  'features/security',
  'features/services',
  'features/support',
  'features/team',
  'features/tokens',
  'features/wallet',
  'features/wordpress',
  'lib',
]

describe('the safe label', () => {
  it('uses the sentence when there is one', () => {
    expect(safeLabel('status', 'active')).toBe(en.status.active)
  })

  it('never renders the value the server sent', () => {
    // The exact shape of the defect: an inventory join key, on the page of a
    // machine the customer is paying for.
    const label = safeLabel('status', 'ded-standard-1')

    expect(label).not.toContain('ded-standard-1')
    expect(label).toBe(en.vocabulary.unknownState)
  })

  it('never renders the key path either', () => {
    const label = safeLabel('planChange.refusal', 'some_reason_nobody_wrote')

    expect(label).not.toContain('planChange')
    expect(label).not.toContain('.')
  })

  it('says something true for a null or an empty value', () => {
    expect(safeLabel('billingPeriod', null)).toBe(en.vocabulary.unknownPeriod)
    expect(safeLabel('billingPeriod', undefined)).toBe(en.vocabulary.unknownPeriod)
    expect(safeLabel('billingPeriod', '')).toBe(en.vocabulary.unknownPeriod)
  })

  it('falls back to the last resort for a namespace nobody registered', () => {
    const label = safeLabel('a.namespace.nobody.registered', 'anything')

    expect(label).toBe(en.vocabulary.unknownValue)
  })

  it('can be asked whether a sentence exists, for slots that should stay empty', () => {
    expect(hasLabel('status', 'active')).toBe(true)
    expect(hasLabel('status', 'not_a_real_status')).toBe(false)
    expect(hasLabel('status', null)).toBe(false)
  })
})

describe('every fallback sentence', () => {
  it.each([
    ['English', en],
    ['Arabic', ar],
  ])('exists in %s', (_language, catalogue) => {
    /*
     * The helper's own safety net needs one: a namespace whose fallback key is
     * missing would render the last-resort sentence instead, which is correct
     * but vague, and vague in a way nobody would notice.
     *
     * The key is what is asserted, not the sentence `safeLabel` returns: that
     * sentence is resolved in whatever language is active, so comparing it
     * against the Arabic catalogue would be checking English twice.
     */
    const flat = flatten(catalogue)

    for (const namespace of [...labelledNamespaces(), 'a.namespace.nobody.registered']) {
      const key = fallbackKey(namespace)

      expect(flat[key], `${namespace} → ${key}`).toBeTruthy()
    }
  })
})

describe('no customer screen falls back to the raw value', () => {
  it('has files to check', () => {
    expect(customerSources().length).toBeGreaterThan(80)
  })

  it('never passes a server value as a translation default', () => {
    /*
     * The pattern this forbids:
     *
     *     t(`status.${vm.service_status}`, { defaultValue: vm.service_status })
     *
     * A `defaultValue` that is itself a translated sentence is fine and is
     * how two of these read — the test looks for a default that is an
     * expression rather than a `t(...)` call, which is exactly the case where
     * a server string reaches the screen.
     */
    const offences: string[] = []

    for (const file of customerSources()) {
      const source = withoutComments(readFileSync(file, 'utf8'))

      for (const match of source.matchAll(/defaultValue:\s*([^,}\n]+)/g)) {
        const fallback = (match[1] ?? '').trim()

        // A default that is itself a `t()` call, or a written literal, is a
        // sentence somebody chose. Anything else is an expression, and an
        // expression here means a server value.
        if (fallback.startsWith('t(') || fallback.startsWith("'") || fallback.startsWith('"')) {
          continue
        }

        offences.push(`${path.relative(SOURCE, file)} → ${fallback}`)
      }
    }

    expect(
      offences,
      'These render whatever the server sent when the catalogue has no sentence. Use safeLabel().',
    ).toEqual([])
  })
})

function customerSources(): string[] {
  const files: string[] = []

  for (const directory of CUSTOMER_DIRECTORIES) {
    walk(path.join(SOURCE, directory), files)
  }

  return files.filter((file) => ! file.includes('__tests__'))
}

function walk(directory: string, into: string[]): void {
  for (const entry of readdirSync(directory)) {
    const full = path.join(directory, entry)

    if (statSync(full).isDirectory()) {
      walk(full, into)
    } else if (full.endsWith('.tsx') || full.endsWith('.ts')) {
      into.push(full)
    }
  }
}

/**
 * Source with comments removed.
 *
 * `safeLabel.ts` documents the exact pattern this gate forbids, and a gate
 * that its own explanation trips is a gate somebody eventually deletes.
 */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}

function flatten(value: Record<string, unknown>, into: Record<string, string> = {}, prefix = ''): Record<string, string> {
  for (const [key, entry] of Object.entries(value)) {
    const full = prefix === '' ? key : `${prefix}.${key}`

    if (typeof entry === 'string') {
      into[full] = entry
    } else if (typeof entry === 'object' && entry !== null) {
      flatten(entry as Record<string, unknown>, into, full)
    }
  }

  return into
}
