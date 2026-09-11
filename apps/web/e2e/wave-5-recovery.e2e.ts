import { expect, test, type Page, type Request } from '@playwright/test'

import { fixtures, forgetSessions, signIn, users } from './support/helpers'

/*
 * Wave 5, §35–§37: what the portal does when a request cannot happen.
 *
 * Three of these journeys are about words on a screen and one is about a
 * number. The number is the important one.
 *
 * A replayed mutation is invisible from the inside. The customer presses
 * Reboot, is told their session has ended, signs in, and the machine
 * reboots — which looks exactly like the thing they asked for, because it is
 * the thing they asked for, under a session that no longer existed, at a
 * moment they did not choose. No screenshot catches that. Counting the
 * requests does.
 *
 * `POST /vps/{id}/power` is the mutation under test, chosen over something
 * harmless on purpose. A reboot is idempotent in shape and emphatically not in
 * effect: performing it twice takes a production machine down a second time,
 * and the second time is the one nobody is expecting. A profile read repeated
 * twice would prove nothing worth proving.
 *
 * Driven against the real API, the real session table and the real fake
 * hypervisor. Nothing here is stubbed — the session is ended by deleting the
 * row, the way it ends in life.
 */

/** Counts the power requests the browser actually puts on the wire. */
function countPowerRequests(page: Page): () => number {
  let count = 0

  const listener = (request: Request): void => {
    if (request.method() === 'POST' && request.url().includes('/power')) count += 1
  }

  page.on('request', listener)

  return () => count
}

async function openOperableMachine(page: Page): Promise<void> {
  await page.goto('/vps')
  await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
  await expect(page.getByRole('button', { name: 'Reboot' }).first()).toBeVisible()
}

test.describe('a session that ends mid-click', () => {
  test('never replays the reboot after the customer signs back in', async ({ page }) => {
    const powerRequests = countPowerRequests(page)

    await signIn(page, users.customer)
    await openOperableMachine(page)

    /*
     * The session ends server-side while the page sits open — the row is
     * deleted, which is what an expiry, a revocation from another device and
     * an administrator signing somebody out all look like to the next request.
     * The browser still holds the cookie and the page still looks signed in,
     * which is precisely the state this is about.
     */
    forgetSessions(users.customer.email)

    await page.getByRole('button', { name: 'Reboot' }).first().click()

    // The deliberate expiry flow, not a wall of red errors.
    const dialog = page.getByRole('alertdialog')
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText(/signed out/i)

    // And it says the thing the customer would otherwise assume the other way.
    await expect(dialog).toContainText(/nothing you had started has been repeated/i)

    expect(powerRequests(), 'one press, one request').toBe(1)

    /*
     * Now sign back in, in this same tab, and give the portal every
     * opportunity to resend: a fresh session, a navigation back to the
     * machine, and the two events TanStack listens to for resuming paused
     * mutations — regaining the network and regaining focus.
     */
    await signIn(page, users.customer)
    await openOperableMachine(page)

    await page.evaluate(() => {
      window.dispatchEvent(new Event('online'))
      window.dispatchEvent(new Event('focus'))
    })

    // A real pause, because a replay would be asynchronous and this test would
    // otherwise be asserting that it had not happened *yet*.
    await page.waitForTimeout(1500)

    expect(
      powerRequests(),
      'the session came back; the reboot must not come back with it',
    ).toBe(1)

    // The machine is reachable and was not rebooted behind their back: the
    // control is still there to be pressed deliberately.
    await expect(page.getByRole('button', { name: 'Reboot' }).first()).toBeVisible()
  })

  test('sends exactly one more when the customer presses it again on purpose', async ({ page }) => {
    /*
     * The other half of the rule, and the reason it is a rule about *replay*
     * rather than about refusing to send. Having said "ask again", the portal
     * has to honour the asking.
     */
    const powerRequests = countPowerRequests(page)

    await signIn(page, users.customer)
    await openOperableMachine(page)

    forgetSessions(users.customer.email)

    await page.getByRole('button', { name: 'Reboot' }).first().click()
    await expect(page.getByRole('alertdialog')).toBeVisible()
    expect(powerRequests()).toBe(1)

    await signIn(page, users.customer)
    await openOperableMachine(page)

    await page.getByRole('button', { name: 'Reboot' }).first().click()

    await expect(
      page.getByRole('region', { name: 'Updates' }).getByText(/Reboot requested/),
    ).toBeVisible()

    expect(powerRequests(), 'the second request is the second press').toBe(2)
  })

  test('lets a read carry the customer back to the page they were on', async ({ page }) => {
    /*
     * Reads are the opposite case and the distinction is the point: re-asking
     * a GET costs nothing and is how a stale page becomes true again, so the
     * expiry flow carries the route and offers to return. Only *writes* are
     * held back.
     */
    await signIn(page, users.customer)
    await openOperableMachine(page)

    const where = new URL(page.url()).pathname

    forgetSessions(users.customer.email)
    await page.getByRole('button', { name: 'Reboot' }).first().click()

    const dialog = page.getByRole('alertdialog')
    await expect(dialog).toBeVisible()

    const signInLink = dialog.getByRole('link', { name: /sign in/i })
    const href = (await signInLink.getAttribute('href')) ?? ''

    expect(href).toContain('/sign-in')
    expect(href).toContain(encodeURIComponent(where))
  })
})

test.describe('a screen that fails to load', () => {
  test('says the read failed instead of saying there is nothing there', async ({ page }) => {
    /*
     * The defect this proves is gone: a failed read leaving a table to render
     * its empty state, so that "we could not reach the platform" reads as "you
     * have no DNS records" — and the customer's remedy for having none is to
     * add them, which is how a zone ends up with every record twice.
     */
    await signIn(page, users.customer)

    /*
     * Its own zone, claimed here. The browser projects share one database and
     * run one after another, so a spec that borrows another's fixture breaks
     * the next project rather than its own.
     */
    const zone = 'e2e-w5-recovery.test'

    await page.goto('/dns')
    await page.getByLabel(/^domain$/i).first().fill(zone)
    await page.getByRole('button', { name: /add domain/i }).click()

    // The read is broken only after the zone exists, so that what fails is the
    // records request and not the claim.
    await page.route(
      (url) => url.pathname.includes('/dns/zones/') && url.pathname.endsWith('/records'),
      (route) => {
        void route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({
            error: { code: 'server.error', message: 'Something went wrong on our side.' },
          }),
        })
      },
    )

    await page.getByRole('link', { name: zone }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: zone })).toBeVisible()

    // The records are their own section of the zone's page, not the landing
    // one: without this the component under test is never mounted and the test
    // passes by having nothing to fail.
    await page
      .getByRole('navigation', { name: /sections/i })
      .getByRole('link', { name: /records/i })
      .click()

    // The failure is stated...
    await expect(page.getByRole('alert').filter({ hasText: /went wrong/i }).first()).toBeVisible()

    // ...and the false reassurance is absent.
    await expect(page.getByText(/no records/i)).toHaveCount(0)
  })
})

test.describe('a browser with no network', () => {
  test('says the connection is gone rather than that the platform refused', async ({
    page,
    context,
  }) => {
    /*
     * The distinction the audit's AC section is about: a customer who has
     * walked into a lift must not be told their reboot failed, and must not be
     * left looking at a spinner either.
     *
     * The request-count half of the offline case — attempted rather than
     * queued, and never replayed on reconnect — is proved in
     * `src/app/__tests__/a-session-that-ended-mid-click.test.tsx`, which drives
     * the real `useVpsPower` against the real QueryClient and counts the
     * fetches. It is asserted there rather than here because the browser's own
     * offline mode aborts the navigation the page needs to reach the control,
     * and a test that worked around that would be testing the workaround. The
     * unit version was verified to fail when the setting is reverted.
     */
    await signIn(page, users.customer)
    await openOperableMachine(page)

    await context.setOffline(true)
    await page.evaluate(() => { window.dispatchEvent(new Event('offline')); })

    // A standing notice, in the customer's own words, about the network.
    await expect(page.getByText(/you are offline/i).first()).toBeVisible()

    /*
     * Navigate within the application rather than reloading. Reloading while
     * offline cannot reach the portal at all — the browser blocks the document
     * and the bundle with everything else — so it would be testing the
     * browser's own error page. A client-side route change keeps the loaded
     * application and gives its new screen a read that has to fail.
     */
    await page
      .getByRole('navigation', { name: /main navigation/i })
      .getByRole('link', { name: 'Activity' })
      .click()

    const main = page.getByRole('main')

    // The honest sentence, from the shared read-failure path...
    await expect(main.getByRole('alert').first()).toContainText(/could not reach the server/i)

    // ...not an indefinite spinner, which is what a paused query left behind
    // before Wave 5 set the read network mode.
    await expect(main.getByText(/^Loading/i)).toHaveCount(0)

    // ...and not the vocabulary of a decision the platform took.
    await expect(main.getByText(/refused|not permitted/i)).toHaveCount(0)

    await context.setOffline(false)
    await page.evaluate(() => { window.dispatchEvent(new Event('online')); })

    await expect(page.getByText(/you are offline/i)).toHaveCount(0)
  })
})
