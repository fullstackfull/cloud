import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §30–§32. Loading, empty, failed and loaded are four states, not two.
 *
 * ## The defect
 *
 * A failed query leaves its `data` undefined. A table handed undefined renders
 * its empty state. So a 403, an expired session, a dropped network and a
 * database that is down all come out as **"you have nothing here"** — stated
 * confidently, with nothing anywhere on the page to suggest otherwise.
 *
 * It is not a cosmetic problem, because "nothing here" is actionable and the
 * action is usually to make one. A customer whose DNS records fail to load
 * adds them again, and the zone now has each record twice. One whose support
 * ticket fails to load opens a second. One whose in-flight restore fails to
 * load starts another over the files the first is still writing.
 *
 * ## The sweep
 *
 * Every customer read surface in the portal was inventoried: 66 of them, in 55
 * files. At the start of Wave 5, **20** did not so much as destructure their
 * query's `error`, which made reporting it structurally impossible. Nine were
 * real instances of the defect above and were fixed. The remaining eleven are
 * listed in `EXEMPT` below, each with the reason it is correct — because a
 * gate whose exemptions have no stated reason becomes a gate with a long list.
 *
 * The worst one found was not a table at all. `RoleChangeDialog` diffs two
 * roles' capabilities to tell an owner what a colleague will gain and lose;
 * on a failed matrix read both lookups missed, both diffs came back empty, and
 * the dialogue rendered "This changes nothing about what they can do" — a
 * false statement, in a confirmation, produced by a read nobody checked.
 *
 * ## What this gate asserts
 *
 * That every query result destructured on a customer screen has its `error`
 * bound to a name, and that the name reaches something that shows it. It
 * cannot prove the customer sees a *good* sentence; the unit tests beside it
 * do that. What it does prove is that no future screen can quietly join the
 * twenty.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

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
]

/**
 * Read surfaces that correctly do not report their own failure, and why.
 *
 * Keyed `file::hook`. Every entry is a claim that can be checked by reading
 * the file, which is the point of writing the reason down rather than the
 * count.
 */
const EXEMPT: Record<string, string> = {
  // The session probe. Its failure is handled once, by RequireAuth, which sits
  // above every one of these in the tree and renders "we could not confirm
  // your sign-in" instead of the route. A page below it cannot be on screen
  // while this read is failing, so reporting it again would be reporting a
  // state that cannot be reached from here.
  'app/AppLayout.tsx::useCurrentUser': 'handled once by RequireAuth, above it in the tree',
  'app/SessionExpiryNotice.tsx::useCurrentUser': 'handled once by RequireAuth',
  'features/account/DashboardPage.tsx::useCurrentUser': 'handled once by RequireAuth',
  'features/account/ProfilePage.tsx::useCurrentUser': 'handled once by RequireAuth',
  'features/auth/VerifyEmailPage.tsx::useCurrentUser': 'handled once by RequireAuth',
  'features/security/TwoFactorSection.tsx::useCurrentUser': 'handled once by RequireAuth',
  'features/team/TeamPage.tsx::useCurrentUser': 'handled once by RequireAuth',

  // The guards are where that handling lives. RequireAuth renders the failure
  // itself rather than through LoadFailure, because a customer who cannot be
  // identified needs a retry and a way to sign in, not an alert strip on a
  // page that should not be drawn. RequireGuest and RequireOperator fail
  // safely by construction: a failed read leaves the visitor on the sign-in
  // page and out of the operator area respectively, which are the outcomes
  // those two would choose anyway.
  'app/guards.tsx::useCurrentUser': 'the guard renders the failure itself; the other two fail safe',

  // A count on a bell icon. The honest rendering of a failed count is no
  // badge: a bell wearing "!" because a request timed out is a notification
  // the customer cannot open, about nothing.
  'app/UnreadBadge.tsx::useUnreadNotificationCount': 'no badge is the honest rendering of an unknown count',
}

interface Surface {
  file: string
  hook: string
  errorBound: string | null
  reported: boolean
}

describe('every customer read surface', () => {
  const surfaces = readSurfaces()

  it('finds the surfaces to check', () => {
    // A floor, so a broken parser cannot pass this file by finding nothing.
    expect(surfaces.length).toBeGreaterThan(50)
  })

  it('reports a failed read rather than rendering it as empty', () => {
    const unreported = surfaces
      .filter((surface) => ! surface.reported)
      .filter((surface) => EXEMPT[`${surface.file}::${surface.hook}`] === undefined)
      .map((surface) => `${surface.file} → ${surface.hook}()`)

    expect(
      unreported,
      'A query whose error is never shown renders a failed read as an empty one. ' +
        'Show it (LoadFailure, or describeError into an Alert), or add it to EXEMPT with the reason.',
    ).toEqual([])
  })

  it('keeps the exemption list honest', () => {
    // An exemption for a surface that no longer exists is a note nobody will
    // ever re-read, and it makes the next person trust the list less.
    const present = new Set(surfaces.map((surface) => `${surface.file}::${surface.hook}`))
    const stale = Object.keys(EXEMPT).filter((key) => ! present.has(key))

    expect(stale, 'Exempted surfaces that no longer exist.').toEqual([])
  })

  it('gives every exemption a reason somebody wrote', () => {
    const empty = Object.entries(EXEMPT)
      .filter(([, reason]) => reason.trim().length < 20)
      .map(([key]) => key)

    expect(empty, 'An exemption without a reason is how this list grows.').toEqual([])
  })
})

function readSurfaces(): Surface[] {
  const surfaces: Surface[] = []
  const destructure = /const\s*\{([^}]*)\}\s*=\s*(use[A-Z]\w*)\s*\(/g

  for (const file of customerSources()) {
    const relative = path.relative(SOURCE, file)
    const source = withoutComments(readFileSync(file, 'utf8'))

    for (const match of source.matchAll(destructure)) {
      const fields = match[1] ?? ''
      const hook = match[2] ?? ''

      // A mutation exposes `mutate`; a query does not. Anything that yields
      // neither data nor a pending flag is not a read surface at all.
      if (fields.includes('mutate')) continue
      if (! /\bisPending\b|\bisLoading\b|\bdata\b/.test(fields)) continue

      const aliased = /\berror\s*:\s*(\w+)/.exec(fields)
      const plain = /\berror\b(?!\s*:)/.test(fields)
      const bound = aliased?.[1] ?? (plain ? 'error' : null)

      surfaces.push({
        file: relative,
        hook,
        errorBound: bound,
        reported: bound !== null && shows(source, bound),
      })
    }
  }

  return surfaces
}

/**
 * Whether a bound error name reaches something that puts it on screen.
 *
 * Three shapes count, because the portal legitimately has three: the shared
 * `LoadFailure` strip, a hand-rolled `Alert` fed by `describeError`, and a
 * component that branches on the error to render its own recovery screen —
 * which is what the auth guard does and what a page-level failure should do.
 */
function shows(source: string, name: string): boolean {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

  return (
    new RegExp(`<LoadFailure[^>]*error=\\{[^}]*\\b${escaped}\\b`).test(source) ||
    new RegExp(`describeError\\([^)]*\\b${escaped}\\b`).test(source) ||
    new RegExp(`\\b${escaped}\\s*!==\\s*null`).test(source) ||
    new RegExp(`capabilitiesUnknown=\\{[^}]*\\b${escaped}\\b`).test(source)
  )
}

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
    } else if (full.endsWith('.tsx')) {
      into.push(full)
    }
  }
}

function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}
