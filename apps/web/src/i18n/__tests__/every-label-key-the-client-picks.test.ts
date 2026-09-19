import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { CONTROL_CENTER_NAV, CUSTOMER_NAV_GROUPS, OPERATOR_NAV_GROUPS } from '@/app/navigation'
import { RESOURCE_FAMILIES } from '@/features/resources/resourcePaths'
import { browserIds, platformIds } from '@/lib/deviceLabel'

/**
 * W5.7. Every translation key this repository composes from its own constants,
 * resolved in both languages.
 *
 * The other half of the dynamic-label gate. That one proves each runtime key
 * comes from a bounded set; this one proves the catalogue actually carries the
 * bounded set — for the sets that live in client code, where no backend enum
 * gate can see them.
 *
 * The keys are read from the source rather than listed here, so that adding a
 * power action, a support category or a navigation entry fails this test until
 * the two sentences exist. Where a list is read by an anchor, a missing anchor
 * fails too: a renamed constant must not quietly take its keys out of the
 * gate.
 */

const SOURCE = path.join(import.meta.dirname, '../..')

const CATALOGUES = {
  English: JSON.parse(readFileSync(path.join(import.meta.dirname, '../locales/en.json'), 'utf8')) as Record<string, unknown>,
  Arabic: JSON.parse(readFileSync(path.join(import.meta.dirname, '../locales/ar.json'), 'utf8')) as Record<string, unknown>,
}

function resolves(catalogue: Record<string, unknown>, key: string): boolean {
  let node: unknown = catalogue

  for (const segment of key.split('.')) {
    if (typeof node !== 'object' || node === null) return false

    node = (node as Record<string, unknown>)[segment]
  }

  return typeof node === 'string' && node.trim() !== ''
}

function customerFiles(): string[] {
  const found: string[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry === '__tests__') continue

        walk(full)
      } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
        found.push(full)
      }
    }
  }

  walk(SOURCE)

  return found
}

/** The string literals of an array, found by an anchor in one file. */
function literalsAfter(file: string, anchor: string): string[] {
  const source = readFileSync(path.join(SOURCE, file), 'utf8')
  const at = source.indexOf(anchor)

  expect(at, `${file} no longer contains ${anchor}`).toBeGreaterThan(-1)

  /*
   * The array's own bracket, not the first bracket after the anchor: a typed
   * declaration — `const CATEGORIES: readonly ActivityCategory[] = [` — has
   * one in the type, and taking that one silently matched nothing at all.
   */
  const start = /=\s*\[|\(\[|\{\[/.exec(source.slice(at))

  expect(start, `${file} has no array after ${anchor}`).not.toBeNull()

  const open = at + (start?.index ?? 0)
  const close = source.indexOf(']', open)

  return [...source.slice(open, close).matchAll(/'([^']+)'/g)].map((match) => match[1] ?? '')
}

/** The translation keys a mapper returns, read from its own literals. */
function keysReturnedBy(file: string, prefix: string): string[] {
  const source = readFileSync(path.join(SOURCE, file), 'utf8')
  const found = [...source.matchAll(new RegExp(`'(${prefix}\\.[a-zA-Z0-9_]+)'`, 'g'))].map(
    (match) => match[1] ?? '',
  )

  expect(found.length, `${file} returns no ${prefix} keys`).toBeGreaterThan(1)

  return found
}

/** The members of a union type declared on one line. */
function unionMembers(file: string, anchor: string): string[] {
  const source = readFileSync(path.join(SOURCE, file), 'utf8')
  const at = source.indexOf(anchor)

  expect(at, `${file} no longer contains ${anchor}`).toBeGreaterThan(-1)

  const line = source.slice(at, source.indexOf('\n', at))

  return [...line.matchAll(/'([^']+)'/g)].map((match) => match[1] ?? '')
}

/** Every key the client composes, with where it came from. */
function clientKeys(): Array<{ key: string; from: string }> {
  const keys: Array<{ key: string; from: string }> = []

  const add = (key: string, from: string): void => {
    keys.push({ key, from })
  }

  // Navigation and resource families, imported rather than parsed.
  for (const group of [...CUSTOMER_NAV_GROUPS, ...OPERATOR_NAV_GROUPS]) {
    if (group.labelKey !== null) add(group.labelKey, `navigation group ${group.id}`)

    for (const item of group.items) add(item.labelKey, `navigation item ${item.to}`)
  }

  for (const item of CONTROL_CENTER_NAV) add(item.labelKey, `control centre item ${item.to}`)

  for (const [kind, family] of Object.entries(RESOURCE_FAMILIES)) {
    add(family.labelKey, `resource family ${kind}`)
  }

  for (const id of browserIds()) add(`security.browsers.${id}`, 'deviceLabel browsers')
  for (const id of platformIds()) add(`security.platforms.${id}`, 'deviceLabel platforms')

  // Tab lists and every other `labelKey: '...'` literal in the portal.
  for (const file of customerFiles()) {
    const source = readFileSync(file, 'utf8')

    for (const match of source.matchAll(/labelKey:\s*'([^']+)'/g)) {
      add(match[1] ?? '', path.relative(SOURCE, file))
    }
  }

  // The bounded lists that compose a key per member.
  const families: Array<{ namespace: string; members: string[]; from: string }> = [
    {
      namespace: 'vps.actions',
      members: literalsAfter('features/infrastructure/vps/VpsPowerActions.tsx', 'const POWER_ACTIONS'),
      from: 'VpsPowerActions POWER_ACTIONS',
    },
    {
      namespace: 'dedicated.actions',
      members: literalsAfter('features/infrastructure/dedicated/DedicatedPowerActions.tsx', 'const POWER_ACTIONS'),
      from: 'DedicatedPowerActions POWER_ACTIONS',
    },
    {
      namespace: 'auth.accountTypes',
      members: literalsAfter('features/auth/RegisterPage.tsx', 'options={([') ,
      from: 'RegisterPage account types',
    },
    {
      namespace: 'support.categories',
      members: literalsAfter('features/support/SupportPage.tsx', "options={['technical'"),
      from: 'SupportPage categories',
    },
    {
      namespace: 'support.priorities',
      members: literalsAfter('features/support/SupportPage.tsx', "options={(['low'"),
      from: 'SupportPage priorities',
    },
    {
      namespace: 'dns.import.modes',
      members: literalsAfter('features/dns/ZoneTransfer.tsx', "options={(['merge'"),
      from: 'ZoneTransfer modes',
    },
    {
      namespace: 'dns.import.modeHint',
      members: literalsAfter('features/dns/ZoneTransfer.tsx', "options={(['merge'"),
      from: 'ZoneTransfer modes',
    },
    {
      namespace: 'dns.import.counts',
      members: literalsAfter('features/dns/ZoneTransfer.tsx', "{(['add'"),
      from: 'ZoneTransfer counts',
    },
    {
      namespace: 'console.state',
      members: unionMembers('features/console/ConsolePage.tsx', 'type ConnectionState'),
      from: 'ConsolePage ConnectionState',
    },
    {
      namespace: 'activity.categories',
      members: literalsAfter('features/activity/ActivityPage.tsx', 'const CATEGORIES'),
      from: 'ActivityPage CATEGORIES',
    },

  ]

  // `panelNameKey` returns whole keys from a switch rather than composing one
  // from a member, so its own literals are the list.
  for (const key of keysReturnedBy('features/infrastructure/hosting/panelName.ts', 'hosting\\.panel')) {
    add(key, 'panelName switch')
  }

  for (const family of families) {
    expect(family.members.length, `${family.from} produced no members`).toBeGreaterThan(1)

    for (const member of family.members) add(`${family.namespace}.${member}`, family.from)
  }

  return keys
}

describe('the keys the client composes from its own constants', () => {
  for (const [language, catalogue] of Object.entries(CATALOGUES)) {
    it(`resolves every one of them in ${language}`, () => {
      const missing = clientKeys()
        .filter(({ key }) => ! resolves(catalogue, key))
        .map(({ key, from }) => `${key}  (${from})`)

      expect([...new Set(missing)], `keys with no ${language} sentence`).toEqual([])
    })
  }

  it('finds a substantial number of them, so a broken scan cannot pass', () => {
    // A parser that silently matched nothing would satisfy every assertion
    // above. The count is a floor rather than a fixture: it moves when the
    // product grows, and it never moves to zero unnoticed.
    expect(new Set(clientKeys().map(({ key }) => key)).size).toBeGreaterThan(60)
  })
})
