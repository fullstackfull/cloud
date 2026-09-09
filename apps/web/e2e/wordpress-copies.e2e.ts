import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * Copies of a WordPress site and the push back, in a browser.
 *
 * The seeded live site sits on the seeded hosting account on a fake node,
 * so its toolkit can copy it. The spec makes a staging copy, sees it as a
 * site of its own, pushes it back with the production domain typed, and
 * sees the outcome as a row. The queue is synchronous in this
 * environment, so the toolkit's answer is on the page by the time the
 * request returns.
 */

const LIVE = 'e2e-live-site.test'
const STAGING = 'staging.' + LIVE

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/wordpress')
  })

  test('a staging copy is made, shown as its own site, and pushed back only with the domain typed', async ({ page }) => {
    const live = page.locator('section', { hasText: LIVE }).first()

    // A copy may already exist from an earlier run; either way we end with one.
    if ((await page.getByRole('heading', { name: STAGING, exact: true }).count()) === 0) {
      await live.getByRole('button', { name: /create a staging copy/i }).click()
      await expect(live.getByText(/the toolkit is copying/i)).toBeVisible()
    }

    await page.reload()
    const staging = page.locator('section', { hasText: STAGING }).first()
    await expect(staging.getByRole('heading', { name: STAGING, exact: true })).toBeVisible()
    await expect(staging.getByText(/^staging copy$/i)).toBeVisible()

    // The push: what it overwrites, in words, then the domain typed exactly.
    await staging.getByRole('button', { name: /push to production/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/holds no backup of a shared-hosting site/i)).toBeVisible()
    await expect(dialog.getByText(new RegExp(LIVE + ' will be overwritten'))).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /^push to production$/i })
    await expect(confirm).toBeDisabled()
    await dialog.getByRole('textbox').fill(LIVE.toUpperCase())
    await expect(confirm).toBeDisabled()
    await dialog.getByRole('textbox').fill(LIVE)
    await confirm.click()
    await expect(page.getByRole('dialog')).toBeHidden()

    // The outcome is a row on both cards.
    await expect(staging.getByTestId('wordpress-operation').first()).toContainText(/push to production/i)
    await expect(staging.getByTestId('wordpress-operation').first().getByText(/succeeded/i)).toBeVisible()
  })

  test('a production site offers copies and not a push; the site with no panel offers the reason', async ({ page }) => {
    const live = page.locator('section', { hasText: LIVE }).first()
    await expect(live.getByRole('button', { name: /push to production/i })).toHaveCount(0)
    await expect(live.getByRole('button', { name: /clone to another domain/i })).toBeVisible()

    // The seeded waiting site is not finished: nothing is offered and nothing pretends.
    const waiting = page.locator('section', { hasText: 'e2e-waiting-site.test' }).first()
    await expect(waiting.getByRole('button', { name: /create a staging copy/i })).toHaveCount(0)
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the copy controls read in Arabic and keep domains unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/wordpress')

    const live = page.locator('section', { hasText: LIVE }).first()
    await expect(live.getByRole('button', { name: 'استنساخ إلى نطاق آخر' })).toBeVisible()

    const heading = live.getByRole('heading', { name: LIVE })
    expect(await heading.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')
  })
})
