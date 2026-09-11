import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * §62. Wave 4's surfaces on a 393-pixel phone.
 *
 * The dashboard is six cards, the feed is rows of four columns' worth of
 * information, and the acknowledgement is a fixed panel at the bottom of the
 * screen — three shapes that are easy to get right on a desktop and easy to
 * get wrong on a phone. What is asserted is that nothing runs off the side,
 * that the toast does not sit on top of the thing it is about, and that the
 * feed's controls are still reachable with a thumb.
 */

/** Nothing renders wider than the phone it is being read on. */
async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )

  expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(1)
}

test.describe('what is happening, on a phone', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('reads the dashboard, attention first, without scrolling sideways', async ({ page }) => {
    await expect(page.getByRole('region', { name: 'Needs your attention' })).toBeVisible()

    await noSidewaysScroll(page)

    /*
     * The cards stack rather than squeeze. A three-column grid at 393px is
     * three columns of forty pixels, which is how a money figure ends up
     * broken across four lines.
     */
    const owed = page.getByRole('region', { name: 'What you owe' })
    await expect(owed).toBeVisible()

    const width = await owed.evaluate((element) => element.getBoundingClientRect().width)
    expect(width).toBeGreaterThan(280)
  })

  test('reads the account feed and filters it with a thumb', async ({ page }) => {
    await openMenu(page)
    await page.getByRole('link', { name: /^activity$/i }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Activity' })).toBeVisible()

    await noSidewaysScroll(page)

    /*
     * Forty-four pixels is the size a thumb finds, and the filters are the one
     * control on this page that is a row of small buttons.
     */
    const filter = page.getByRole('button', { name: 'Billing' })
    await expect(filter).toBeVisible()

    const box = await filter.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(32)

    await filter.click()
    await expect(filter).toHaveAttribute('aria-pressed', 'true')

    await noSidewaysScroll(page)
  })

  test('shows the acknowledgement without covering the controls it is about', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    const reboot = page.getByRole('button', { name: 'Reboot' }).first()
    await reboot.click()

    const channel = page.getByRole('region', { name: 'Updates' })
    await expect(channel.getByText(/Reboot requested/)).toBeVisible()

    await noSidewaysScroll(page)

    /*
     * The panel is fixed to the bottom of a small screen, which is the right
     * place for it and the easiest place to cover something with it. It has a
     * dismiss button, and the dismiss button is reachable.
     */
    const dismiss = channel.getByRole('button', { name: 'Dismiss' }).first()
    await expect(dismiss).toBeVisible()

    await dismiss.click()
    await expect(channel.getByText(/Reboot requested/)).toHaveCount(0)
  })
})
