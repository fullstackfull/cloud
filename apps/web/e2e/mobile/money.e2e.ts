import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/**
 * The money screens on a phone, which is where most customers open an invoice.
 *
 * The specific risks on 393px are the ones a desktop run cannot see: a table
 * that makes the page scroll sideways, a total that wraps away from its label,
 * and a print link that a thumb cannot reach.
 */

test.describe('an invoice on a phone', () => {
  test('the document reads without the page scrolling sideways', async ({ page }) => {
    await signIn(page)

    await openMenu(page)
    await page.getByRole('link', { name: /^invoices$/i }).click()

    /*
     * By the name a screen reader hears. W5.6 renamed the row link from
     * "View" — ten identical links on a page of ten invoices — to carry
     * the number it opens, so addressing it this way also means this
     * journey cannot open the wrong invoice and pass.
     */
    await page
      .getByRole('row', { name: new RegExp(fixtures.openInvoice) })
      .getByRole('link', { name: new RegExp(`view invoice ${fixtures.openInvoice}`, 'i') })
      .click()

    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.openInvoice) }),
    ).toBeVisible()

    // The address and the lines, both on the screen.
    await expect(page.getByText(/addressed to/i)).toBeVisible()
    await expect(page.getByRole('table', { name: /what you bought/i })).toBeVisible()

    /*
     * The body does not scroll sideways. Wide things — the line table — scroll
     * inside their own box, which is the arrangement that keeps a phone
     * usable; a horizontally scrolling page is unusable in either direction
     * and worse in Arabic.
     */
    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))

    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
  })

  test('paying from a phone reaches the provider page and settles by webhook', async ({ page }) => {
    // The money account: this journey settles an invoice, and the shared
    // fixture is what the rest of the suite reads.
    await signIn(page, users.moneyCustomer)

    await page.goto('/invoices')

    /*
     * By the name a screen reader hears. W5.6 renamed the row link from
     * "View" — ten identical links on a page of ten invoices — to carry
     * the number it opens, so addressing it this way also means this
     * journey cannot open the wrong invoice and pass.
     */
    await page
      .getByRole('row', { name: new RegExp(fixtures.phonePaymentInvoice) })
      .getByRole('link', { name: new RegExp(`view invoice ${fixtures.phonePaymentInvoice}`, 'i') })
      .click()

    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.phonePaymentInvoice) }),
    ).toBeVisible()

    await page.getByRole('button', { name: /^pay$/i }).click()

    await expect(page).toHaveURL(/\/fake-gateway\/authorise\//)
    await expect(page.getByText(/not a real provider/i)).toBeVisible()

    await page.getByRole('button', { name: /approve the payment/i }).click()

    await expect(page).toHaveURL(/\/invoices\//)
    await expect(page.getByText(/what we have recorded for this payment/i)).toBeVisible()
    await expect(page.getByText(/^paid$/i).first()).toBeVisible()
  })
})

test.describe('the catalogue price on a phone', () => {
  test('the breakdown and the renewal figure both fit', async ({ page }) => {
    await signIn(page)

    await page.goto('/catalogue')
    await page.getByRole('link', { name: /view plans/i }).first().click()

    await page.getByRole('button', { name: /monthly/i }).first().click()

    await expect(page.getByText(/due now/i)).toBeVisible()
    await expect(page.getByText(/then monthly/i)).toBeVisible()

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))

    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
  })
})

test.describe('the wallet ledger on a phone', () => {
  test('the history is reachable and its rows carry the balance after each one', async ({ page }) => {
    await signIn(page)

    // Opened directly: the drawer is what e2e/mobile/navigation.e2e.ts is for,
    // and what this spec is about is the ledger on a narrow screen.
    await page.goto('/wallet')

    await expect(page.getByRole('table', { name: /credit history/i })).toBeVisible()
    // The column, by its role: the card's own description ends with the same
    // words, and a loose text match finds both.
    await expect(page.getByRole('columnheader', { name: /balance after/i })).toBeVisible()
  })
})
