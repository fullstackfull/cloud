import { expect, test, type Page } from '@playwright/test'

import { expectNoPageOverflow, pageOverflow, unheldOverflow } from '../support/layout'
import { fixtures, signIn, users } from '../support/helpers'

/*
 * §4, §19. The page does not scroll sideways, at 393 and at 360.
 *
 * This spec runs in two projects, on two real phone descriptors, and that is
 * the point: §4 says 360 is a separate check and not 393 minus a bit. The
 * assertions are identical; only the width differs, and the failure message
 * names which width it was.
 *
 * What it asserts is the whole page's own geometry —
 * `documentElement.scrollWidth` against `clientWidth` — because that is the
 * only measurement that catches every route to the same defect. The one it
 * caught was not visible in any screenshot: an `sr-only` heading, which is
 * `position: absolute`, was escaping a `position: static` scroll container
 * (an absolutely positioned box is clipped only by an ancestor that is also
 * its containing block) and landing in the document's scroll area. Nine
 * screens dragged sideways by up to 306px with nothing drawn out there to
 * show why. A reviewer looking at pictures could not have found it; this
 * measurement found it on the first run.
 *
 * Horizontal scrolling *inside* a deliberate container stays allowed (§5).
 * The second test below asserts that it is still happening, so that a future
 * fix cannot make this one pass by hiding a table.
 */

/** Every screen a customer reaches without a resource identity in the address. */
const DIRECT = [
  ['dashboard', '/'],
  ['activity', '/activity'],
  ['catalogue', '/catalogue'],
  ['services', '/services'],
  ['cloud servers', '/vps'],
  ['dedicated servers', '/dedicated'],
  ['hosting', '/hosting'],
  ['WordPress', '/wordpress'],
  ['domains', '/domains'],
  ['DNS', '/dns'],
  ['addresses', '/ips'],
  ['backups', '/backups'],
  ['orders', '/orders'],
  ['invoices', '/invoices'],
  ['payments', '/payments'],
  ['subscriptions', '/subscriptions'],
  ['wallet', '/wallet'],
  ['notifications', '/notifications'],
  ['support', '/support'],
  ['profile', '/profile'],
  ['team', '/settings/team'],
  ['security', '/security'],
  ['API tokens', '/api-tokens'],
  ['email verification', '/verify-email'],
] as const

/** And the resource pages, which are reached by name and then by section. */
const BY_NAME = [
  ['a cloud server', '/vps', fixtures.operableHostname, ['', 'networking', 'billing', 'danger']],
  ['a dedicated server', '/dedicated', fixtures.dedicatedSerial, ['', 'billing', 'danger']],
  ['a hosting account', '/hosting', fixtures.hostingDomain, ['', 'billing']],
  ['a WordPress site', '/wordpress', fixtures.liveSite, ['', 'copies', 'billing']],
  ['a domain', '/domains', fixtures.heldDomain, ['', 'contacts', 'nameservers']],
  ['a DNS zone', '/dns', fixtures.heldDomain, ['', 'records']],
] as const

test.describe('a narrow screen', () => {
  test.describe.configure({ timeout: 600_000 })

  test('never scrolls the page sideways', async ({ page }) => {
    await signIn(page, users.customer)

    for (const [name, address] of DIRECT) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
      await settle(page)
      await expectNoPageOverflow(page, name)
    }
  })

  test('never scrolls the page sideways on a resource page either', async ({ page }) => {
    await signIn(page, users.customer)

    for (const [name, index, identity, sections] of BY_NAME) {
      await page.goto(index)
      await page.locator('main').getByRole('link', { name: identity, exact: true }).first().click()
      await page.waitForURL((url) => url.pathname !== index)
      const base = new URL(page.url()).pathname

      for (const section of sections) {
        await page.goto(section === '' ? base : `${base}/${section}`)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await settle(page)
        await expectNoPageOverflow(page, section === '' ? name : `${name} — ${section}`)
      }
    }
  })

  test('scrolls a wide table inside the table, which is the point', async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/services')
    await expect(page.getByRole('table')).toBeVisible()
    await settle(page)

    /*
     * §5's distinction, asserted from both sides. The page must not scroll,
     * and the table must — otherwise the next person to see this test pass
     * could get there by hiding the columns that did not fit, which §5 and
     * §23 both forbid.
     */
    expect(await pageOverflow(page), 'the page').toBeLessThanOrEqual(1)

    const scroller = page.locator('main div').filter({ has: page.locator('table') }).last()
    const local = await scroller.evaluate((element) => element.scrollWidth - element.clientWidth)

    expect(local, 'the table has more columns than the phone is wide, and must scroll itself')
      .toBeGreaterThan(50)
  })

  test('keeps every visible thing inside the viewport', async ({ page }) => {
    await signIn(page, users.customer)

    /*
     * The page measurement above is the gate; this is the complement. A page
     * can measure zero overflow and still push a control off the start edge
     * with a negative margin, which the root's scrollWidth does not see.
     */
    for (const address of ['/', '/security', '/api-tokens', '/settings/team', '/invoices']) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
      await settle(page)

      const offenders = await unheldOverflow(page)

      expect(
        offenders,
        `${address} puts something past the viewport edge with nothing holding it`,
      ).toEqual([])
    }
  })
})

/**
 * Long enough for the screen's own reads to land, and not a network-idle wait:
 * the dashboard and the activity feed poll, so "no requests for 500ms" is a
 * condition some of these screens never reach.
 */
async function settle(page: Page): Promise<void> {
  await page.waitForTimeout(1200)
}
