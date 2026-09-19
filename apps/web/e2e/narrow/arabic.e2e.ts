import { expect, test } from '@playwright/test'

import { expectNoPageOverflow, pageOverflow, unheldOverflow } from '../support/layout'
import { fixtures, signIn, users } from '../support/helpers'

/*
 * §7, §25. Arabic on a narrow phone.
 *
 * The Arabic project already drives real journeys, but at desktop width. This
 * is the corner the matrix leaves otherwise uncovered: right-to-left *and*
 * 360px, where a page that overflows does so off the *start* edge — to the
 * left — which is a different measurement and a worse experience, because the
 * content a reader begins at is the content that has gone off the screen.
 *
 * It runs in both narrow projects, so every assertion is made at 393 and 360,
 * and it reaches Arabic through the portal's own language control on the
 * sign-in screen rather than through a browser locale, because that is the
 * route a customer who is already on the page takes.
 */

test.describe('the portal in Arabic on a narrow screen', () => {
  test.describe.configure({ timeout: 300_000 })

  test.beforeEach(async ({ page }) => {
    await page.goto('/sign-in')
    await page.getByRole('button', { name: 'العربية' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
  })

  test('never scrolls the page sideways, in either direction', async ({ page }) => {
    for (const address of [
      '/',
      '/activity',
      '/services',
      '/vps',
      '/invoices',
      '/payments',
      '/subscriptions',
      '/settings/team',
      '/security',
      '/api-tokens',
      '/support',
      '/profile',
    ]) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
      await page.waitForTimeout(1200)

      // The helper reads `html.dir` and measures past the start edge in RTL,
      // so this is the same assertion the English run makes, mirrored.
      await expectNoPageOverflow(page, `${address} in Arabic`)

      const offenders = await unheldOverflow(page)
      expect(offenders, `${address} in Arabic puts something past the start edge`).toEqual([])
    }
  })

  test('keeps the navigation on the start edge and the drawer with it', async ({ page }) => {
    await page.goto('/')

    const hamburger = page.getByRole('button', { name: /^القائمة$|^menu$/i })
    await expect(hamburger).toBeVisible()

    /*
     * The drawer opens from the side the hamburger is on, which in Arabic is
     * the right. Asserted by geometry rather than by class, because `me-auto`
     * is the mechanism and its effect is what matters: a drawer that opened
     * from the left in Arabic would cover the content a reader starts at.
     */
    await hamburger.click()
    const drawer = page.getByRole('dialog')
    await expect(drawer).toBeVisible()

    const width = page.viewportSize()?.width ?? 0
    const box = await drawer.boundingBox()

    expect(Math.round((box?.x ?? 0) + (box?.width ?? 0)), 'the drawer does not reach the start edge')
      .toBeGreaterThanOrEqual(width - 1)
    expect(Math.round(box?.width ?? 0), 'the drawer is wider than the screen')
      .toBeLessThanOrEqual(width)

    expect(await pageOverflow(page), 'the page behind the Arabic drawer').toBeLessThanOrEqual(1)
  })

  test('leaves a technical identifier reading left to right', async ({ page }) => {
    await page.goto('/vps')
    await page.locator('main').getByRole('link', { name: fixtures.operableHostname, exact: true }).click()

    const identity = page.getByRole('heading', { level: 1 })
    await expect(identity).toBeVisible()
    await expect(identity).toContainText(fixtures.operableHostname)

    /*
     * §7's rule, measured: a hostname is not mirrored. The check is on the
     * resolved direction of the element that holds it, because that is what
     * decides how the browser lays the characters out — an address whose
     * octets render in the wrong order is a different address.
     */
    const direction = await identity
      .locator('[dir="ltr"]')
      .first()
      .evaluate((element) => getComputedStyle(element).direction)

    expect(direction, 'the hostname is mirrored on an Arabic page').toBe('ltr')

    await expectNoPageOverflow(page, 'a machine page in Arabic')
  })
})
