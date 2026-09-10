import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { CONTROL_CENTER_NAV, CUSTOMER_NAV, OPERATOR_NAV } from '@/app/navigation'

/**
 * Two gates the audit's findings were made of.
 *
 * **The route inventory.** Every address the portal serves is either in the
 * navigation or reachable from a page that is. The audit found the opposite in
 * both directions: destinations hidden behind a "More" disclosure, and pages
 * with no way in at all. A route added without a way to reach it fails here.
 *
 * **No dead capability.** Every hook in the data layer is called by something.
 * This is the gate that would have caught what Wave 3 spent most of its time
 * on: the platform could list a machine's installable images, read a hosting
 * account's real usage, renew a domain, set its auto-renew, read and correct
 * its registrant, and edit a DNS record — and not one of those endpoints had a
 * caller anywhere in the portal.
 *
 * Both walk the source rather than a list typed here, so they keep working as
 * the portal grows.
 */

const APP_ROOT = path.resolve(import.meta.dirname, '..')
const SOURCE_ROOT = path.resolve(APP_ROOT, '..')

/** Every `path="…"` declared in the router, in declaration order. */
function declaredRoutes(): string[] {
  const source = readFileSync(path.join(APP_ROOT, 'App.tsx'), 'utf8')

  return [...source.matchAll(/path="([^"]+)"/g)].map((match) => match[1] ?? '')
}

/**
 * Addresses that are deliberately not in the navigation, each with the reason
 * it is not.
 *
 * A navigation is a list of places a person goes looking for something. None
 * of these is that: they are arrived at from a link, an email, or a button on
 * another screen.
 */
const REACHED_FROM_ELSEWHERE: Record<string, string> = {
  '/sign-in': 'where a signed-out visitor lands',
  '/register': 'linked from sign-in',
  '/forgot-password': 'linked from sign-in',
  '/reset-password': 'where a reset email lands',
  '/verify-email': 'where a verification email lands',
  '/login': 'a legacy path kept so an old bookmark still works',
  '*': 'the not-found page',
  '/invitations/:token': 'where an invitation email lands',
  '/fake-gateway/authorise/:reference': "the controlled provider's own payment page",
  '/invoices/:id/print': 'opened from an invoice, and deliberately without the chrome',
  '/subscriptions/:id/plan': 'opened from a subscription and from a resource billing section',
  '/catalogue/:slug': 'opened from the catalogue',
  '/vps/:id/console': "opened from a machine's page",
}

describe('the route inventory', () => {
  const routes = declaredRoutes()

  it('has a way in to every address it serves', () => {
    const navigable = new Set(
      [...CUSTOMER_NAV, ...OPERATOR_NAV, ...CONTROL_CENTER_NAV].map((item) => item.to),
    )

    const orphans = routes.filter((route) => {
      if (navigable.has(route)) return false
      if (Object.hasOwn(REACHED_FROM_ELSEWHERE, route)) return false

      /*
       * A resource page and its sections: `/vps/:id`, `/vps/:id/backups`,
       * `/domains/:identity/contacts`. Each is reached from the family's
       * index, which is itself in the navigation, and each is an address a
       * customer can share — which is the whole point of them being routes.
       */
      const family = `/${route.split('/')[1] ?? ''}`

      return !navigable.has(family)
    })

    expect(orphans).toEqual([])
  })

  it('still serves every address the navigation points at', () => {
    // The other direction: a navigation entry whose route was renamed is a
    // link to the not-found page, which is worse than no link.
    const declared = new Set(routes)
    const missing = [...CUSTOMER_NAV, ...OPERATOR_NAV, ...CONTROL_CENTER_NAV]
      .map((item) => item.to)
      .filter((to) => to !== '/' && !declared.has(to))

    expect(missing).toEqual([])
  })

  it('keeps the addresses that existed before the resource pages', () => {
    /*
     * The indexes. Wave 3 turned each of these into an index in front of a
     * page per thing, and none of them moved: a bookmark, a support macro or
     * an old email still lands where it did.
     */
    for (const kept of [
      '/services',
      '/vps',
      '/vps/:id/console',
      '/dedicated',
      '/hosting',
      '/wordpress',
      '/domains',
      '/dns',
      '/backups',
      '/ips',
    ]) {
      expect(routes, `${kept} must still be served`).toContain(kept)
    }
  })
})

describe('no dead capability', () => {
  it('has a caller for every hook the data layer publishes', () => {
    const queries = readFileSync(path.join(SOURCE_ROOT, 'lib/queries.ts'), 'utf8')
    const hooks = [...queries.matchAll(/^export function (use\w+)/gm)].map((match) => match[1] ?? '')

    // A sanity floor: if the regex ever stops matching, the gate must fail
    // rather than pass on an empty list.
    expect(hooks.length).toBeGreaterThan(80)

    const sources = sourceFiles(SOURCE_ROOT)
      .filter((file) => file !== 'lib/queries.ts')
      .map((file) => readFileSync(path.join(SOURCE_ROOT, file), 'utf8'))
      .join('\n')

    const uncalled = hooks.filter((hook) => !new RegExp(`\\b${hook}\\b`).test(sources))

    expect(uncalled).toEqual([])
  })
})

function sourceFiles(directory: string, prefix = ''): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`

    if (entry.isDirectory()) {
      // Tests do not count as callers: a capability proven only by its own
      // test is still a capability no customer can reach.
      return entry.name === '__tests__'
        ? []
        : sourceFiles(path.join(directory, entry.name), relative)
    }

    return /\.tsx?$/.test(entry.name) ? [relative] : []
  })
}
