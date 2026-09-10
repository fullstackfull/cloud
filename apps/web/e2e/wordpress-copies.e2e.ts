import { expect, test, type Page } from '@playwright/test'

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

/**
 * Opens one site's copies, which since Wave 3 live on the site's own page.
 *
 * The index still answers "where has each site got to"; the copies, the push
 * and the operation history are on the site, which is where somebody working
 * on one site is.
 */
async function openCopies(page: Page, domain: string): Promise<void> {
  await page.goto('/wordpress')
  await page.getByRole('link', { name: domain, exact: true }).first().click()
  await expect(page.getByRole('heading', { level: 1, name: domain })).toBeVisible()
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name: /^copies$/i }).click()
}

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/wordpress')
  })

  test('a staging copy is made, shown as its own site, and pushed back only with the domain typed', async ({ page }) => {
    // A copy may already exist from an earlier run; either way we end with one.
    if ((await page.getByRole('link', { name: STAGING, exact: true }).count()) === 0) {
      await openCopies(page, LIVE)
      await page.getByRole('button', { name: /create a staging copy/i }).click()
      await expect(page.getByText(/the toolkit is copying/i)).toBeVisible()
    }

    // The copy is a site of its own on the index, and says which kind it is.
    await page.goto('/wordpress')
    const staging = page.locator('section', { hasText: STAGING }).first()
    await expect(staging.getByRole('link', { name: STAGING, exact: true })).toBeVisible()
    await expect(staging.getByText(/^staging copy$/i)).toBeVisible()

    // The push: what it overwrites, in words, then the domain typed exactly.
    await openCopies(page, STAGING)
    await page.getByRole('button', { name: /push to production/i }).click()
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

    // The outcome is a row in the site's own history.
    await expect(page.getByTestId('wordpress-operation').first()).toContainText(/push to production/i)
    await expect(page.getByTestId('wordpress-operation').first().getByText(/succeeded/i)).toBeVisible()
  })

  test('a production site offers copies and not a push; the site with no panel offers the reason', async ({ page }) => {
    await openCopies(page, LIVE)
    await expect(page.getByRole('button', { name: /push to production/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /clone to another domain/i })).toBeVisible()

    // The seeded waiting site is not finished: nothing is offered and nothing
    // pretends.
    await openCopies(page, 'e2e-waiting-site.test')
    await expect(page.getByRole('button', { name: /create a staging copy/i })).toHaveCount(0)
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the copy controls read in Arabic and keep domains unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await page.goto('/wordpress')
    await page.getByRole('link', { name: LIVE, exact: true }).first().click()

    const heading = page.getByRole('heading', { level: 1, name: LIVE })
    await expect(heading).toBeVisible()
    /*
     * The heading is Arabic-direction prose; the name inside it declares its
     * own direction, which is what keeps a Latin identifier from mirroring.
     */
    const name = heading.locator('[dir="ltr"]')
    await expect(name).toHaveText(LIVE)
    expect(await name.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'النسخ' })
      .click()

    await expect(page.getByRole('button', { name: 'استنساخ إلى نطاق آخر' })).toBeVisible()
  })
})
