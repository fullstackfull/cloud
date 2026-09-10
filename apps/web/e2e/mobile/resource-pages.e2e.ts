import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * A resource page on a phone.
 *
 * The page shape Wave 3 introduced has two things that fail on a phone if
 * nobody checks: a row of section links, which wraps into three rows or makes
 * the page scroll sideways, and a header with several controls in it, which
 * ends up as buttons a thumb cannot hit. Both are asserted here on a real
 * phone descriptor rather than on a desktop window made narrow.
 */

/** The page never scrolls sideways at 393 pixels. */
async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )

  expect(overflow).toBeLessThanOrEqual(1)
}

test.describe('a resource page on a phone', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('opens a machine from the drawer and reads it without scrolling sideways', async ({
    page,
  }) => {
    await (await openMenu(page)).getByRole('link', { name: /^cloud vps$/i }).click()

    await page.getByRole('link', { name: fixtures.operableHostname }).click()
    await expect(page.getByRole('heading', { level: 1, name: fixtures.operableHostname })).toBeVisible()

    await noSidewaysScroll(page)

    // The facts a customer came for, on the section that costs one request.
    await expect(page.getByText(fixtures.operableAddress)).toBeVisible()
  })

  test('reaches every section, including the ones past the edge of the screen', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()

    const sections = page.getByRole('navigation', { name: /sections/i })

    /*
     * Six sections do not fit across 393 pixels, and they are not meant to:
     * the row scrolls inside its own box rather than wrapping or dragging the
     * page with it. A section below the fold of that row is still reachable.
     */
    for (const name of [/^networking$/i, /^backups$/i, /^activity$/i, /^billing$/i, /^danger zone$/i]) {
      const link = sections.getByRole('link', { name })
      await link.scrollIntoViewIfNeeded()
      await expect(link).toBeVisible()
      await link.click()
      await expect(link).toHaveAttribute('aria-current', 'page')
      await noSidewaysScroll(page)
    }
  })

  test('a destructive control is reachable, asks first, and can be backed out of', async ({
    page,
  }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()

    const danger = page
      .getByRole('navigation', { name: /sections/i })
      .getByRole('link', { name: /^danger zone$/i })

    await danger.scrollIntoViewIfNeeded()
    await danger.click()

    const reinstall = page.getByRole('button', { name: /^reinstall$/i })
    await expect(reinstall).toBeVisible()

    // The touch target, not just the visibility: a 24-pixel button is a
    // button a thumb misses.
    const box = await reinstall.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(28)

    await reinstall.click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/the server disk will be replaced/i)).toBeVisible()

    // The dialogue fits the screen: a confirmation whose button is off the
    // edge is a confirmation nobody can give or refuse.
    await expect(dialog.getByRole('button', { name: /^cancel$/i })).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})
