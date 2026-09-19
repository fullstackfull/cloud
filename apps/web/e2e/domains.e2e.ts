import { expect, test, type Page } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * Buying and holding a name, in a browser.
 *
 * This needs a real browser and a real API because the two things most worth
 * checking are things a unit test cannot see: whether the screen renders the
 * answer that is neither yes nor no, and whether it stops a customer acting on
 * an outcome nobody has established.
 *
 * A registrar that does not answer is not a rare case. It is the case that
 * costs money — a customer told "available" buys a name somebody else owns,
 * and a customer told "failed" orders a second one on top of a registration
 * that may have succeeded.
 */

/** Opens one name's own page, from the index, by the name itself. */
async function openName(page: Page, name: string): Promise<void> {
  await page.goto('/domains')
  await page.getByRole('link', { name }).first().click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

/** Moves to one section of the name's page. The sections are links, not tabs. */
async function section(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name }).click()
}

const HELD = 'e2e-held.test'
const UNSURE = 'e2e-unsure.test'
const LAPSED = 'e2e-lapsed.test'
const LOST = 'e2e-lost.example'

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('a free name can be bought and a taken one cannot', async ({ page }) => {
    await page.goto('/domains')

    await page.getByLabel(/domain name/i).fill('somethingfree.test')
    await page.getByRole('button', { name: /^search$/i }).click()

    const free = page.getByRole('row', { name: /somethingfree\.test/ })
    await expect(free).toBeVisible()
    await expect(free.getByRole('button', { name: /^buy$/i })).toBeVisible()

    await page.getByLabel(/domain name/i).fill('already-taken.test')
    await page.getByRole('button', { name: /^search$/i }).click()

    const taken = page.getByRole('row', { name: /already-taken\.test/ })
    // Exact, because the name itself contains the word.
    await expect(taken.getByText('Taken', { exact: true })).toBeVisible()
    await expect(taken.getByRole('button', { name: /^buy$/i })).toHaveCount(0)
  })

  test('a registrar that did not answer is shown as its own answer and offers no purchase', async ({
    page,
  }) => {
    await page.goto('/domains')

    // The fake registrar refuses to answer for this marker, which is what a
    // real one does when it times out.
    await page.getByLabel(/domain name/i).fill('quiet-unreachable.test')
    await page.getByRole('button', { name: /^search$/i }).click()

    const row = page.getByRole('row', { name: /quiet-unreachable\.test/ })

    /*
     * Neither "available" nor "taken". Both of those would be the screen
     * inventing an answer the platform does not have, and both cost somebody
     * money — one sells a name that is owned, the other turns away a sale.
     */
    await expect(row.getByText('No answer', { exact: true })).toBeVisible()
    await expect(row.getByRole('button', { name: /^buy$/i })).toHaveCount(0)

    // And no price. A zero here would read as free.
    await expect(row.getByText('No price', { exact: true })).toBeVisible()
  })

  test('a premium name is priced as itself rather than at the ordinary price', async ({ page }) => {
    await page.goto('/domains')

    await page.getByLabel(/domain name/i).fill('jackpot-premium.test')
    await page.getByRole('button', { name: /^search$/i }).click()

    const row = page.getByRole('row', { name: /jackpot-premium\.test/ })
    await expect(row.getByText(/premium/i).first()).toBeVisible()

    // The list price for this namespace is 3.500 KWD. A premium name showing
    // that number is the substitution the quote table exists to prevent.
    await expect(row.getByText('KWD 3.500')).toHaveCount(0)
  })

  test('a name the platform holds shows its delegation and the way out', async ({ page }) => {
    // Since Wave 3 the name has an address of its own, reached from the index
    // by the name itself.
    await openName(page, HELD)

    await section(page, /^nameservers$/i)
    await expect(page.getByText('ns1.lynomia.test')).toBeVisible()

    // Leaving is offered plainly. A platform that buries this has stopped
    // competing on being worth staying with.
    await section(page, /^moving away$/i)
    await expect(page.getByRole('button', { name: /transfer code/i })).toBeEnabled()
    await expect(page.getByText(/we do not make that difficult/i)).toBeVisible()
  })

  test('a name the platform cannot vouch for says so and offers nothing to act on', async ({
    page,
  }) => {
    await openName(page, UNSURE)

    /*
     * The Timeout Rule reaching a customer. The words that matter are "do not
     * try again": a screen that merely looked broken would invite a second
     * order on top of a registration that may already have succeeded.
     */
    await expect(page.getByText(/do not try again/i)).toBeVisible()

    /*
     * And nothing to act on. Since Wave 3 the controls are visible and
     * disabled rather than absent, with the sentence that says why: a missing
     * button leaves a customer wondering whether the platform can do the
     * thing at all, and a disabled one with no reason is worse.
     */
    await expect(page.getByRole('button', { name: /renew now/i }).first()).toBeDisabled()

    await section(page, /^moving away$/i)
    await expect(page.getByRole('button', { name: /transfer code/i })).toBeDisabled()
    await expect(page.getByText(/cannot act on this name at the moment/i)).toBeVisible()

    await section(page, /^nameservers$/i)
    await expect(page.getByRole('button', { name: /^save$/i })).toBeDisabled()
  })
})

test.describe('a customer whose name lapsed', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('sees the redemption penalty, orders the recovery, and is sent to the invoice', async ({ page }) => {
    await openName(page, LAPSED)

    const card = page.getByRole('region', { name: /recovering this name/i })
    await expect(card.getByText(/redemption window/i)).toBeVisible()
    // The registry's penalty, from the catalogue: 25.000 KWD on this namespace.
    await expect(card.getByText(/recovered for the registry's redemption penalty of/i)).toContainText('25.000')
    // No renewal offered: the ordinary price does not apply, and the control
    // that would quote it is off.
    await expect(page.getByRole('button', { name: /renew now/i }).first()).toBeDisabled()

    await card.getByRole('button', { name: /recover this name/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/redemption penalty/i).first()).toBeVisible()
    await expect(dialog.getByText(/quote valid until/i)).toBeVisible()
    await dialog.getByRole('button', { name: /order the recovery/i }).click()

    // Nothing is sent to the registry before payment; the screen says so and
    // links the invoice.
    await expect(card.getByText(/waiting for payment/i)).toBeVisible()
    await card.getByRole('link', { name: /open the invoice/i }).click()
    await expect(page).toHaveURL(/\/invoices$/)
    // The penalty, invoiced: 25.000 KWD on this namespace.
    await expect(page.getByText(/25\.000/).first()).toBeVisible()
  })

  test('is told plainly when a namespace cannot be recovered, and offered nothing', async ({ page }) => {
    await openName(page, LOST)

    const card = page.getByRole('region', { name: /recovering this name/i })
    await expect(card.getByText(/redemption window/i)).toBeVisible()
    await expect(card.getByText(/we will not quote a guess/i)).toBeVisible()
    await expect(card.getByRole('button', { name: /recover this name/i })).toHaveCount(0)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the domains screens read in Arabic and keep names unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await openName(page, HELD)

    /*
     * The heading is Arabic-direction prose; the name inside it declares its
     * own direction, which is what keeps a Latin identifier from mirroring.
     */
    const heading = page.getByRole('heading', { level: 1, name: HELD })
    const name = heading.locator('[dir="ltr"]')
    await expect(name).toHaveText(HELD)
    expect(await name.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    // The same refusal to guess, in Arabic, on the name it is about.
    await openName(page, UNSURE)
    await expect(page.getByText(/لا تُعِد المحاولة/)).toBeVisible()

    await openName(page, LOST)
    const redemption = page.getByRole('region', { name: 'استرداد هذا الاسم' })
    await expect(redemption.getByText(/لن نعرض تخميناً/)).toBeVisible()

    // And the search, which is where a name is bought rather than managed.
    await page.goto('/domains')
    await page.getByLabel('اسم النطاق').fill('quiet-unreachable.test')
    await page.getByRole('button', { name: 'بحث' }).click()

    await expect(
      page.getByRole('row', { name: /quiet-unreachable\.test/ }).getByText('لا جواب'),
    ).toBeVisible()
  })
})
