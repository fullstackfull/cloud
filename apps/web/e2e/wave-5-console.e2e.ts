import { type Page, expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * What the browser says while a customer uses the portal.
 *
 * W5.9 §24 asks for representative journeys watched for console errors, React
 * warnings, failed requests, duplicate mutation requests, runaway polling and
 * requests to endpoints that no longer exist — and asks that expected
 * simulated failures stay distinguishable from genuine defects.
 *
 * Nothing here simulates a failure. That is the whole design: this spec walks
 * the portal the way it is meant to work, so every error the browser reports
 * is a defect by construction and no allow-list is needed to tell the two
 * apart. The specs that *do* simulate failures — a refused payment, a dead
 * network, a 404 read — live elsewhere and are expected to be noisy.
 *
 * The one exception is the sign-in page's own identity probe, which asks who
 * is signed in before anybody is and is answered 401 by design. It is
 * excused by name below rather than by a pattern broad enough to hide a
 * second one.
 */

/** A request the portal makes whose failure is the correct answer. */
const EXPECTED: Array<{ match: RegExp; why: string }> = [
  {
    match: /\/api\/v1\/me$/,
    why:
      'The identity probe on a cold load. The portal asks who is signed in before it knows, and 401 ' +
      'is the answer that tells it to show the sign-in form. Only ever a 401, and only before a session ' +
      'exists.',
  },
]

interface Seen {
  consoleErrors: string[]
  pageErrors: string[]
  warnings: string[]
  failed: string[]
  requests: Array<{ method: string; url: string; at: number }>
}

function watch(page: Page): Seen {
  const seen: Seen = { consoleErrors: [], pageErrors: [], warnings: [], failed: [], requests: [] }

  page.on('console', (message) => {
    const text = message.text()

    if (message.type() === 'error') {
      /*
       * The browser logs a console error for a failed *resource*, separately
       * from the response event, and the message itself carries no URL — only
       * `location()` does. So the identity probe has to be excused twice, in
       * both places, or the walk fails on the one refusal that is correct.
       */
      const from = message.location().url

      if (EXPECTED.some((e) => e.match.test(from)) && text.includes('401')) return

      seen.consoleErrors.push(`${text} @ ${from}`)

      return
    }

    /*
     * React's own complaints only. A browser warns about all sorts of things
     * that are not the portal's doing — a deprecated CSS property in a font
     * stylesheet, a cookie a proxy set — and collecting every warning would
     * make this gate a list of other people's problems.
     */
    if (message.type() === 'warning' && /React|Warning:|act\(|key prop|deprecated/i.test(text)) {
      seen.warnings.push(text)
    }
  })

  page.on('pageerror', (error) => {
    seen.pageErrors.push(error.message)
  })

  page.on('requestfailed', (request) => {
    // An aborted request is the portal cancelling its own in-flight read on
    // navigation, which is correct behaviour and not a failure.
    const failure = request.failure()?.errorText ?? ''

    if (failure.includes('ERR_ABORTED') || failure.includes('NS_BINDING_ABORTED')) return

    seen.failed.push(`${request.method()} ${request.url()} — ${failure}`)
  })

  page.on('response', (response) => {
    if (response.status() < 400) return

    const url = response.url()
    const excused = EXPECTED.some((e) => e.match.test(url))

    if (excused && response.status() === 401) return

    seen.failed.push(`${response.request().method()} ${url} — HTTP ${response.status()}`)
  })

  page.on('request', (request) => {
    if (! request.url().includes('/api/')) return

    seen.requests.push({ method: request.method(), url: request.url(), at: Date.now() })
  })

  return seen
}

function report(seen: Seen): string {
  return [
    seen.pageErrors.length > 0 ? `Uncaught errors:\n  ${seen.pageErrors.join('\n  ')}` : '',
    seen.consoleErrors.length > 0 ? `Console errors:\n  ${seen.consoleErrors.join('\n  ')}` : '',
    seen.warnings.length > 0 ? `React warnings:\n  ${seen.warnings.join('\n  ')}` : '',
    seen.failed.length > 0 ? `Failed requests:\n  ${seen.failed.join('\n  ')}` : '',
  ]
    .filter((part) => part !== '')
    .join('\n\n')
}

test.describe('what the browser says while the portal is used', () => {
  test.describe.configure({ timeout: 180_000 })

  test('a walk through the portal reports nothing to the console and fails no request', async ({ page }) => {
    const seen = watch(page)

    await signIn(page, users.customer)

    /*
     * Representative rather than exhaustive: one screen of each kind the
     * portal has — an aggregate, a list, a resource with tabs, money, an
     * account setting — because a console error is a property of a component
     * tree rather than of an address, and the same trees are drawn everywhere.
     */
    for (const address of [
      '/',
      '/services',
      '/vps',
      '/invoices',
      '/payments',
      '/subscriptions',
      '/wallet',
      '/domains',
      '/dns',
      '/activity',
      '/notifications',
      '/support',
      '/settings/team',
      '/security',
      '/api-tokens',
      '/profile',
      '/catalogue',
    ]) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
    }

    // A resource with its own tabs, which is where the most component work is.
    await page.goto('/vps')
    await page
      .locator('main')
      .getByRole('link', { name: fixtures.operableHostname, exact: true })
      .first()
      .click()
    await page.waitForURL((url) => url.pathname !== '/vps')

    for (const section of ['', '/networking', '/backups', '/billing', '/danger']) {
      await page.goto(`${new URL(page.url()).pathname}${section}`)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
    }

    expect(
      report(seen),
      'The browser reported something while the portal was being used as intended. Nothing here ' +
        'simulates a failure, so each of these is a defect rather than an expected refusal.',
    ).toBe('')

    // And the walk really happened, so the emptiness above means something.
    expect(seen.requests.length, 'No API requests were seen at all.').toBeGreaterThan(20)
  })

  test('a screen with nothing in flight stops asking, and no mutation is sent twice', async ({ page }) => {
    const seen = watch(page)

    await signIn(page, users.customer)

    /*
     * Measured while sitting still, and only then.
     *
     * Two corrections went into this, and both were wrong about the product
     * rather than about the portal.
     *
     * The first version measured across navigations and reported `/me` and the
     * unread count as runaway at gaps of about a second. They were not: each
     * page load legitimately re-reads both, and two loads a second apart look
     * exactly like a one-second interval to anything counting gaps. Polling is
     * a property of a screen left open, so the log is cleared once each screen
     * has settled and only the idle window is measured.
     *
     * The second version then asserted that *something* must be read while
     * sitting still, and forty idle seconds produced nothing at all. That is
     * the design: `nextListPollDelay` opens with `if (unfinished === 0) return
     * false`, so a screen with no operation in flight does not poll — it goes
     * quiet. Which is a stronger property than "nothing polls too fast", and
     * is what this now asserts.
     */
    const arrival: Seen['requests'] = []
    const idleReads: Seen['requests'] = []

    for (const address of ['/', '/activity']) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()

      // Let the arrival finish before the clock starts.
      await page.waitForTimeout(3_000)
      arrival.push(...seen.requests)
      seen.requests.length = 0

      await page.waitForTimeout(20_000)
      idleReads.push(...seen.requests)
    }

    const reads = idleReads.filter((r) => r.method === 'GET')
    const byUrl = new Map<string, number[]>()

    for (const read of reads) {
      // Without the query string: a page cursor is a different URL and the
      // same read.
      const key = read.url.split('?')[0] ?? read.url
      byUrl.set(key, [...(byUrl.get(key) ?? []), read.at])
    }

    const runaway: string[] = []

    for (const [url, times] of byUrl) {
      if (times.length < 3) continue

      const gaps = times.slice(1).map((at, index) => at - (times[index] ?? at))
      const fastest = Math.min(...gaps)

      /*
       * 2.5 seconds, against a server hint of 3s for a settling operation and
       * a 10s floor for lists. The margin is for scheduling, not for a second
       * interval nobody meant to start.
       */
      if (fastest < 2_500) {
        runaway.push(`${url} — ${times.length} reads, fastest gap ${fastest}ms`)
      }
    }

    expect(runaway, 'A read repeated faster than any interval the portal sets.').toEqual([])

    /*
     * And the quiet itself. Forty seconds on the two screens that poll, with
     * nothing in flight, must produce no repeated read of anything: an
     * interval left running against an idle account is the runaway this
     * section is really about, and it would be invisible to a gap check that
     * only looks at how fast.
     */
    const repeated = [...byUrl.entries()]
      .filter(([, times]) => times.length > 1)
      .map(([url, times]) => `${url} — ${times.length} reads while idle`)

    expect(
      repeated,
      'A screen with nothing in flight kept reading. nextListPollDelay returns false when nothing is '
        + 'unfinished, so an idle screen should go quiet.',
    ).toEqual([])

    const mutations = idleReads.filter((r) => r.method !== 'GET' && r.method !== 'HEAD')
    const duplicates = mutations
      .map((m) => `${m.method} ${m.url}`)
      .filter((key, index, all) => all.indexOf(key) !== index)

    expect(
      [...new Set(duplicates)],
      'A mutation was sent more than once during a walk that asked for it once.',
    ).toEqual([])

    /*
     * The walk happened. The assertions above are all about absence, so
     * something has to prove the browser was doing anything at all — and it
     * cannot be the idle window, which is meant to be empty. The arrival
     * reads, captured before each idle window began, are that evidence.
     */
    expect(arrival.length, 'Neither screen read anything even on arrival, so nothing here was exercised.')
      .toBeGreaterThan(4)
  })

  test('no request goes to an endpoint the API does not have', async ({ page }) => {
    const seen = watch(page)

    await signIn(page, users.customer)

    for (const address of ['/', '/services', '/invoices', '/settings/team', '/security', '/api-tokens']) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
    }

    /*
     * A 404 from the API on a read the portal made by itself means the portal
     * is asking for something that is not there — an address left behind by a
     * rename, which is exactly §21's obsolete compatibility route seen from
     * the other end. Distinct from the 404s a *customer* can cause by typing
     * an address, which no journey here does.
     */
    const missing = seen.failed.filter((line) => line.includes('HTTP 404'))

    expect(missing, 'The portal asked the API for an endpoint it does not have.').toEqual([])
  })
})
