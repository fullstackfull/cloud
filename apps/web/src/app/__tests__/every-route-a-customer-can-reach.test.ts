import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { CONTROL_CENTER_NAV, CUSTOMER_NAV, OPERATOR_NAV } from '@/app/navigation'

/**
 * W5.7. The route table, the navigation and every link in the portal, checked
 * against each other.
 *
 * Three things can be wrong between a router and the screens around it, and
 * none of them is visible in a screenshot:
 *
 *  - **A navigation entry to a route that is not declared.** The customer
 *    presses it and lands on the not-found page — the portal offering
 *    something the platform does not have, which is how a support ticket
 *    starts. The audit found the phone drawer doing this.
 *  - **A route nothing can reach.** Built, wired, translated, and reachable
 *    only by typing the address. Sometimes that is deliberate — a printable
 *    invoice, an invitation link — and then it is written down here with the
 *    reason. Otherwise it is dead weight that still has to be maintained.
 *  - **An operator route in the customer's navigation.** A courtesy failure
 *    rather than a security one, because every operator endpoint checks its
 *    own permission — but a customer offered a screen that answers 403 to
 *    everything has been told the platform is broken.
 *
 * The inventory itself is derived here rather than maintained by hand, so it
 * cannot drift from `App.tsx`.
 */

const APP = path.join(import.meta.dirname, '..')
const SOURCE = path.join(APP, '..')

/**
 * Routes a customer can reach only by address, and why each is deliberate.
 *
 * An entry is a product decision, not a to-do. Every one of these is arrived
 * at from somewhere — a mail link, a row in a list, a redirect from a payment
 * provider — rather than from the navigation, which lists places rather than
 * documents.
 */
const DEEP_LINK_ONLY: Record<string, string> = {
  '/verify-email': 'Where the link in the verification mail lands.',
  '/sign-in': 'The signed-out shell; the navigation belongs to the signed-in one.',
  '/register': 'Reached from the sign-in page and from marketing.',
  '/forgot-password': 'Reached from the sign-in page.',
  '/reset-password': 'Where the link in the password-reset mail lands.',
  '/invitations/:token': 'Where the link in an invitation mail lands, before the person belongs to any account.',
  '/catalogue/:slug': 'One product, opened from the catalogue.',
  '/orders/:id': 'One order, opened from the orders list.',
  '/invoices/:id': 'One invoice, opened from the invoices list.',
  '/invoices/:id/print': 'The printable copy, opened from the invoice it prints.',
  '/subscriptions/:id/plan': 'Changing one subscription’s plan, opened from that subscription.',
  '/fake-gateway/authorise/:reference':
    'Where the controlled payment provider sends the customer back; refused outright in production.',
  '/vps/:id': 'One machine, opened from the machines list.',
  '/vps/:id/console': 'A full-screen terminal, opened from the machine it connects to.',
  '/dedicated/:id': 'One server, opened from the servers list.',
  '/hosting/:id': 'One hosting account, opened from the hosting list.',
  '/wordpress/:id': 'One site, opened from the sites list.',
  '/domains/:identity': 'One domain, opened from the domains list.',
  '/dns/:identity': 'One zone, opened from the DNS list.',
  '/login': 'A legacy address kept so an old bookmark still lands on the sign-in page.',
  '/*': 'The not-found page, where every address the portal does not declare lands.',
}

/** Sections of a resource page, which are reached from the page's own tabs. */
const SECTION_OF_A_RESOURCE = /^\/(vps|dedicated|hosting|wordpress|domains|dns)\/:[a-z]+\/[a-z]+$/

interface Route {
  path: string
  isIndex: boolean
}

/**
 * Every route `App.tsx` declares, as a full path.
 *
 * Parsed by walking the JSX and keeping a stack of open `<Route>` elements,
 * because the nesting is the point: `/vps/:id/backups` exists as a child of
 * `/vps/:id`, and a reader of the file sees one path per line.
 */
function declaredRoutes(): Route[] {
  const source = readFileSync(path.join(APP, 'App.tsx'), 'utf8')
  const routes: Route[] = []
  const stack: string[] = []

  /*
   * Scanned rather than matched with one regular expression, and the reason is
   * worth writing down: a route's attributes contain JSX —
   * `element={<VpsDetailPage />}` — so the first `>` after `<Route` is inside
   * the element, not the end of the tag. A regex that stopped there read every
   * parent as childless and produced `/billing` where the route is
   * `/vps/:id/billing`.
   */
  for (let index = 0; index < source.length; index += 1) {
    if (source.startsWith('</Route>', index)) {
      stack.pop()
      index += 7

      continue
    }

    if (! source.startsWith('<Route', index)) continue

    let depth = 0
    let cursor = index + 6

    while (cursor < source.length) {
      const character = source[cursor]

      if (character === '{') depth += 1
      if (character === '}') depth -= 1
      if (character === '>' && depth === 0) break

      cursor += 1
    }

    const attributes = source.slice(index + 6, cursor)
    const selfClosing = attributes.trimEnd().endsWith('/')
    const declared = /path="([^"]+)"/.exec(attributes)?.[1] ?? null
    const isIndex = /\bindex\b/.test(attributes)

    const parent = stack.join('')
    const full =
      declared === null
        ? parent
        : declared.startsWith('/')
          ? declared
          : `${parent}/${declared}`

    if (declared !== null || isIndex) routes.push({ path: full === '' ? '/' : full, isIndex })

    if (! selfClosing) stack.push(declared === null ? '' : full)

    index = cursor
  }

  return routes
}

const ROUTES = declaredRoutes()

function isOperator(route: string): boolean {
  return route.startsWith('/admin')
}

/** Whether a concrete address matches a declared route pattern. */
function matches(target: string, pattern: string): boolean {
  const wanted = target.split('?')[0]?.split('#')[0] ?? target
  const left = wanted.split('/').filter((segment) => segment !== '')
  const right = pattern.split('/').filter((segment) => segment !== '')

  if (left.length !== right.length) return false

  return right.every((segment, index) => segment.startsWith(':') || segment === left[index])
}

function resolvable(target: string): boolean {
  if (target === '/') return ROUTES.some((route) => route.isIndex)

  return ROUTES.some((route) => matches(target, route.path))
}

/** Every literal internal address the portal links to or navigates to. */
function linkTargets(): Array<{ target: string; where: string }> {
  const targets: Array<{ target: string; where: string }> = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry === '__tests__') continue

        walk(full)

        continue
      }

      if (! full.endsWith('.ts') && ! full.endsWith('.tsx')) continue

      const source = readFileSync(full, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

      for (const match of source.matchAll(
        /(?:to=\{?["'`]|navigate\(["'`]|to:\s*["'`]|href=["'`])(\/[^"'`$\\{)]*)/g,
      )) {
        targets.push({ target: match[1] ?? '', where: path.relative(SOURCE, full) })
      }
    }
  }

  walk(SOURCE)

  return targets
}

describe('the routes a customer can reach', () => {
  it('declares the number of routes the wave report records', () => {
    // A floor and a ceiling, so that a parser which silently stopped finding
    // routes cannot satisfy the assertions below by finding none.
    expect(ROUTES.length).toBeGreaterThan(70)
    expect(ROUTES.filter((route) => ! isOperator(route.path)).length).toBeGreaterThan(50)
  })

  it('offers no navigation entry that lands nowhere', () => {
    const broken = [...CUSTOMER_NAV, ...OPERATOR_NAV, ...CONTROL_CENTER_NAV]
      .map((item) => item.to)
      .filter((target) => ! resolvable(target))

    expect(broken, 'navigation destinations with no route').toEqual([])
  })

  it('links nowhere that does not exist', () => {
    const broken = linkTargets()
      .filter(({ target }) => ! resolvable(target))
      .map(({ target, where }) => `${target}  <- ${where}`)

    expect([...new Set(broken)], 'links to routes that are not declared').toEqual([])
  })

  it('reaches every customer route from the navigation, or says why not', () => {
    const reachable = new Set(CUSTOMER_NAV.map((item) => item.to))

    const orphans = ROUTES.filter((route) => ! isOperator(route.path))
      .filter((route) => ! route.isIndex)
      .map((route) => route.path)
      .filter((route) => ! reachable.has(route))
      .filter((route) => ! SECTION_OF_A_RESOURCE.test(route))
      .filter((route) => ! (route in DEEP_LINK_ONLY))

    expect(
      orphans,
      'customer routes that nothing in the portal navigates to. Either link them, or add them ' +
        'to DEEP_LINK_ONLY with the reason they are reached by address.',
    ).toEqual([])
  })

  it('has no stale entry in the deep-link list', () => {
    const declared = new Set(ROUTES.map((route) => route.path))

    expect(
      Object.keys(DEEP_LINK_ONLY).filter((route) => ! declared.has(route)),
      'deep-link reasons for routes that no longer exist',
    ).toEqual([])
  })

  it('gives every deep-link route a reason somebody wrote', () => {
    for (const [route, reason] of Object.entries(DEEP_LINK_ONLY)) {
      expect(reason.length, `${route} has no reason`).toBeGreaterThan(20)
      expect(reason.endsWith('.'), `${route}'s reason is not a sentence`).toBe(true)
    }
  })

  it('still serves the addresses that existed before the resource pages', () => {
    /*
     * Wave 3 turned each of these into an index in front of a page per thing,
     * and none of them moved: a bookmark, a support macro or an old email
     * still lands where it did. Moved here from the Wave 3 inventory test,
     * which W5.7 merged into this file — two files parsing the router and
     * keeping two lists of deep-link reasons was the duplication this wave
     * was asked to find.
     */
    const declared = new Set(ROUTES.map((route) => route.path))

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
      expect(declared, `${kept} must still be served`).toContain(kept)
    }
  })

  it('keeps the operator area out of the customer navigation', () => {
    expect(CUSTOMER_NAV.filter((item) => isOperator(item.to)).map((item) => item.to)).toEqual([])

    // And the other way: an operator list must not carry a customer screen,
    // which would put a second entry point to it in a different vocabulary.
    expect(
      [...OPERATOR_NAV, ...CONTROL_CENTER_NAV]
        .filter((item) => ! isOperator(item.to))
        .map((item) => item.to),
    ).toEqual([])
  })
})
