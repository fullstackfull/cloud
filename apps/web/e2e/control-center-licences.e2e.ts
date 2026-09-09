import { expect, test, type Page } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * The licence screen: what was bought and when it stops being true.
 *
 * The three seeded licences are chosen so the calendar, not a flag, decides
 * what the screen says — in force, expiring inside the thirty-day window, and
 * lapsed. What the browser proves on top of PHPUnit is that those three read
 * as three different words, that the operator's one override needs a reason,
 * and that the sweep can be run from the screen.
 *
 * Fixtures come from E2ESeeder::licences.
 */

function row(page: Page, product: string) {
  return page.getByRole('row').filter({ hasText: product })
}

test.describe('an operator reading the licences', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/licences')
    await expect(page.getByRole('heading', { name: /^licences$/i })).toBeVisible()
  })

  test('sees in-force, expiring and expired as three different things, lapsed first', async ({ page }) => {
    await expect(row(page, 'cpanel')).toContainText(/^(?=.*active)(?!.*expiring)/i)
    await expect(row(page, 'directadmin')).toContainText(/expiring/i)
    await expect(row(page, 'directadmin')).toContainText(/needs attention/i)
    await expect(row(page, 'litespeed')).toContainText(/expired/i)
    await expect(row(page, 'litespeed')).toContainText(/days ago/i)

    // What needs a person first.
    const products = await page.getByRole('row').locator('.technical').allTextContents()
    expect(products.findIndex((text) => text.includes('litespeed'))).toBeLessThan(
      products.findIndex((text) => text.includes('cpanel')),
    )
  })

  test('can run the calendar sweep from the screen', async ({ page }) => {
    await page.getByRole('button', { name: /refresh from calendar/i }).click()
    await expect(page.getByRole('button', { name: /refreshed/i })).toBeVisible()
  })

  test('marks a licence invalid only with a reason, and the row carries it', async ({ page }) => {
    await row(page, 'cpanel').getByRole('button', { name: /mark invalid/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: /mark invalid/i })).toBeDisabled()

    await dialog.getByLabel(/why\?/i).fill('Vendor says the order was refunded.')
    await dialog.getByRole('button', { name: /mark invalid/i }).click()

    await expect(row(page, 'cpanel').getByText(/^invalid$/i)).toBeVisible()
    await expect(row(page, 'cpanel')).toContainText(/order was refunded/i)
  })

  test('records a renewal, which clears the invalidation', async ({ page }) => {
    // Consumes the state the previous spec left. Ordered by file position.
    await row(page, 'cpanel').getByRole('button', { name: /^renew$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: /^renew$/i })).toBeDisabled()

    const nextYear = new Date()
    nextYear.setFullYear(nextYear.getFullYear() + 1)
    await dialog.getByLabel(/new expiry/i).fill(nextYear.toISOString().slice(0, 10))
    await dialog.getByRole('button', { name: /^renew$/i }).click()

    // The state badge, exactly — the row also carries a "Mark invalid" button,
    // so a loose "not invalid" would have failed on the control's own label.
    await expect(row(page, 'cpanel').getByText(/^active$/i)).toBeVisible()
    await expect(row(page, 'cpanel').getByText(/^invalid$/i)).toHaveCount(0)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the licence screen reads in Arabic', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/licences')

    await expect(page.getByRole('heading', { name: /التراخيص/ })).toBeVisible()
    await expect(row(page, 'litespeed')).toContainText(/منتهي الصلاحية/)
    await expect(row(page, 'directadmin')).toContainText(/يوشك على الانتهاء/)
  })
})
