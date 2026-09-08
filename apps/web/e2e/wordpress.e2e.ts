import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * WordPress sites, in a browser.
 *
 * A site is four things that have to line up, and the reason this needs a
 * browser is that the screen's job is to say which of the four is outstanding.
 * Getting that wrong does not break anything — it fills a support queue with
 * customers who all ask the same question because the page told none of them
 * anything.
 */

const LIVE = 'e2e-live-site.test'
const WAITING = 'e2e-waiting-site.test'
const STUCK = 'e2e-stuck-site.test'

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/wordpress')
  })

  test('a finished site says all four steps are done', async ({ page }) => {
    const card = page.locator('section', { hasText: LIVE }).first()

    await expect(page.getByRole('heading', { name: LIVE })).toBeVisible()

    // The last step is the only one that is this platform's own observation.
    await expect(card.getByText(/we loaded the site and it answered/i)).toBeVisible()
    await expect(card.getByRole('link', { name: new RegExp(LIVE) })).toBeVisible()
  })

  test('a site waiting on the customer says so, and says what to do', async ({ page }) => {
    const card = page.locator('section', { hasText: WAITING }).first()

    /*
     * The most useful sentence on the page. This customer is waiting on
     * themselves, and a spinner would leave them refreshing while nothing
     * happens — because nothing is going to until they act.
     */
    await expect(card.getByText(/change its nameservers at the company/i)).toBeVisible()
  })

  test('a site nobody can fix by retrying says not to try again', async ({ page }) => {
    const card = page.locator('section', { hasText: STUCK }).first()

    // The Timeout Rule on a screen: a second order over a half-finished
    // install takes with it whatever the customer already wrote.
    await expect(card.getByText(/do not order this site again/i)).toBeVisible()
  })

  test('the obvious administrator name is refused before anything is built', async ({ page }) => {
    await page.getByRole('button', { name: /new wordpress site/i }).click()

    await page.getByLabel(/domain name/i).fill('brandnew.test')
    await page.getByLabel(/wordpress username/i).fill('admin')
    await page.getByLabel(/wordpress email/i).fill('owner@brandnew.test')

    await page.getByRole('button', { name: /create the site/i }).click()

    /*
     * "admin" is the first name every credential-stuffing run tries, and a
     * platform that let it through would be creating that account for every
     * customer who did not think about it.
     *
     * Asserted by what did not happen: no card for the name appears, and the
     * form is still open. Asserting on the error text alone would pass
     * against a label that is always on the page.
     */
    await expect(page.getByRole('heading', { name: 'brandnew.test' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /create the site/i })).toBeVisible()
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the WordPress screen reads in Arabic and keeps names unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/wordpress')

    const heading = page.getByRole('heading', { name: LIVE })
    await expect(heading).toBeVisible()

    // A domain name is technical and must not be mirrored.
    expect(await heading.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    // Both sentences that decide what a waiting customer does next.
    const waiting = page.locator('section', { hasText: WAITING }).first()
    await expect(waiting.getByText(/غيّر خوادم أسمائه/)).toBeVisible()

    const stuck = page.locator('section', { hasText: STUCK }).first()
    await expect(stuck.getByText(/لا تطلب هذا الموقع مجدداً/)).toBeVisible()
  })
})
