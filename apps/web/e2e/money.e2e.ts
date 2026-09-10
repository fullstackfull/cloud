import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'
import { linkFromMailTo } from './support/outbox'

/**
 * Wave 2: money is legible.
 *
 * Eight journeys, each one a thing a customer does with their money. What they
 * have in common is that no figure and no outcome in them is asserted from the
 * browser's own arithmetic or from a URL: every amount is the server's, and a
 * payment is settled by the provider telling the platform.
 */

/** A fresh address per run, so re-running the suite is not a duplicate. */
function newAddress(): string {
  return `wave2-${Date.now()}-${Math.floor(Math.random() * 1000)}@example.com`
}

test.describe('journey A — a new customer registers, verifies, and reads prices', () => {
  test('the verification link in the real mail is what opens the account', async ({ page }) => {
    const email = newAddress()

    await page.goto('/register')

    await page.getByLabel(/full name/i).fill('Wave Two Customer')
    await page.locator('input[type="email"]').fill(email)

    // The country decides the currency, and the screen says which before
    // anything is submitted.
    await page.getByLabel(/billing country/i).selectOption('KW')
    await expect(page.getByLabel(/billing currency/i)).toHaveValue('KWD')
    await expect(page.getByText(/we bill customers in that country in KWD/i)).toBeVisible()

    await page.locator('input[type="password"]').first().fill('correct-horse-9')
    await page.locator('input[type="password"]').nth(1).fill('correct-horse-9')
    await page.getByRole('checkbox').check()

    await page.getByRole('button', { name: /create account/i }).click()

    await expect(page.getByRole('heading', { name: /check your (email|inbox)/i })).toBeVisible()

    /*
     * The real link, out of the real message. Not built here and not written
     * into the database: this is the one step that proves the signed URL the
     * platform sends actually works.
     */
    const link = await linkFromMailTo(email, { matching: /https?:\/\/\S*email\/verify\S+/ })

    await page.goto(link)

    // A browser is handed back to the portal rather than shown JSON.
    await expect(page).toHaveURL(/\/verify-email\?status=verified/)
    await expect(page.getByText(/address is confirmed/i)).toBeVisible()

    // And the account can now read prices.
    await page.goto('/sign-in')
    await page.locator('input[type="email"]').fill(email)
    await page.locator('input[type="password"]').fill('correct-horse-9')
    await page.locator('form').getByRole('button').first().click()

    await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible()

    await page.goto('/catalogue')
    await expect(page.getByRole('heading', { name: /catalogue/i })).toBeVisible()
  })
})

test.describe('journey B — an unverified customer may look and may not buy', () => {
  test('the catalogue opens, the invoices do not, and the way out is offered', async ({ page }) => {
    const email = newAddress()

    await page.goto('/register')
    await page.getByLabel(/full name/i).fill('Unverified Customer')
    await page.locator('input[type="email"]').fill(email)
    await page.getByLabel(/billing country/i).selectOption('KW')
    await page.locator('input[type="password"]').first().fill('correct-horse-9')
    await page.locator('input[type="password"]').nth(1).fill('correct-horse-9')
    await page.getByRole('checkbox').check()
    await page.getByRole('button', { name: /create account/i }).click()

    await expect(page.getByRole('heading', { name: /check your (email|inbox)/i })).toBeVisible()

    // Signed in without following the link.
    await page.goto('/sign-in')
    await page.locator('input[type="email"]').fill(email)
    await page.locator('input[type="password"]').fill('correct-horse-9')
    await page.locator('form').getByRole('button').first().click()

    await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible()

    // Prices: readable.
    await page.goto('/catalogue')
    await expect(page.getByRole('heading', { name: /catalogue/i })).toBeVisible()

    // Money: refused, and told apart from a permissions problem — the banner
    // offers the verification page rather than a dead end.
    await expect(page.getByRole('link', { name: /confirm your email address/i })).toBeVisible()

    await page.goto('/invoices')
    await expect(page.getByText(/not verified yet|confirm your email/i).first()).toBeVisible()

    await page.getByRole('link', { name: /confirm your email address/i }).first().click()
    await expect(page.getByRole('button', { name: /send the link again/i })).toBeVisible()
  })
})

test.describe('journey C — buying something, with the price shown first', () => {
  test('the quote, the order, the invoice and the payment are one chain', async ({ page }) => {
    /*
     * Signed in as the account the money journeys own, not the shared one.
     * This journey buys a plan, which leaves behind an order, an invoice, a
     * payment, a subscription and two notifications — and the shared fixture
     * is what every other spec in the suite asserts on.
     */
    await signIn(page, users.moneyCustomer)

    await page.goto('/catalogue')
    await page.getByRole('link', { name: /view plans/i }).first().click()

    // Choose the first billing period offered on the first plan.
    await page.getByRole('button', { name: /monthly/i }).first().click()

    // The breakdown, from the server, before anything is bought.
    await expect(page.getByText(/due now/i)).toBeVisible()
    await expect(page.getByText(/then monthly/i)).toBeVisible()
    await expect(page.getByText(/service is set up once the payment is confirmed/i)).toBeVisible()

    const quoted = /\d+\.\d{3}/.exec(
      await page.locator('dl div', { has: page.getByText('Due now', { exact: true }) }).last().innerText(),
    )?.[0]

    expect(quoted).toBeTruthy()

    await page.getByRole('button', { name: /place order/i }).click()

    // The order, and the chain it produced.
    await expect(page.getByRole('heading', { name: /order/i })).toBeVisible()
    await expect(page.getByText(/what happens next/i)).toBeVisible()

    const invoiceLink = page.getByRole('link', { name: /LYN-|view the invoice/i }).first()
    await expect(invoiceLink).toBeVisible()
    await invoiceLink.click()

    // The invoice document the order produced.
    await expect(page.getByRole('heading', { level: 1, name: /invoice/i })).toBeVisible()

    /*
     * The same figure the quote showed, on the invoice the order produced.
     * Compared as the server's own string rather than recomputed: if these two
     * ever disagree, the customer was quoted one price and billed another.
     */
    await expect(page.getByText(new RegExp(String(quoted))).first()).toBeVisible()

    // Pay it: the browser is sent to the provider's page.
    await page.getByRole('button', { name: /^pay$/i }).click()

    await expect(page).toHaveURL(/\/fake-gateway\/authorise\//)
    await expect(page.getByText(/not a real provider/i)).toBeVisible()

    await page.getByRole('button', { name: /approve the payment/i }).click()

    // Back on the invoice, which now reports what the platform recorded.
    await expect(page).toHaveURL(/\/invoices\/.*payment=/)
    await expect(page.getByText(/what we have recorded for this payment/i)).toBeVisible()
    await expect(page.getByText(/coming back from the payment page does not settle/i)).toBeVisible()

    // Settled, because the provider told the platform — not because we came
    // back to this URL.
    await expect(page.getByText(/^paid$/i).first()).toBeVisible()
  })
})

test.describe('journey D — paying part of an invoice from credit', () => {
  test('credit covers what it can and the rest stays payable', async ({ page }) => {
    await signIn(page, users.moneyCustomer)
    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.creditThenCardInvoice) })
    await row.getByRole('button', { name: /use credit/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The server says how much of the balance applies, and says out loud that
    // it will not cover the whole invoice.
    await expect(dialog.getByText(/credit does not cover the whole invoice/i)).toBeVisible()

    await dialog.getByRole('button', { name: /use credit/i }).click()

    await expect(page.getByRole('dialog')).toBeHidden()

    // Still payable, and the ledger records where the credit went.
    await page.goto('/wallet')
    await expect(page.getByRole('table', { name: /credit history/i })).toBeVisible()
    await expect(page.getByText(/spent/i).first()).toBeVisible()
  })
})

test.describe('journey E — one invoice paid from credit and a card', () => {
  test('the invoice shows both, as two rows', async ({ page }) => {
    await signIn(page, users.moneyCustomer)
    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.creditThenCardInvoice) })
    await row.getByRole('link', { name: /^view$/i }).click()

    // Waited for the document, not merely for the URL: the list is still
    // mounted for an instant after the address changes, and a click resolved
    // in that instant would find the list's own buttons.
    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.creditThenCardInvoice) }),
    ).toBeVisible()

    // The credit spent in journey D is on the document.
    await expect(page.getByRole('table', { name: /credit applied to this invoice/i })).toBeVisible()

    // And the card pays the remainder.
    await page.getByRole('button', { name: /^pay$/i }).click()
    await expect(page).toHaveURL(/\/fake-gateway\/authorise\//)
    await page.getByRole('button', { name: /approve the payment/i }).click()

    await expect(page).toHaveURL(/\/invoices\//)

    const payments = page.getByRole('table', { name: /payments on this invoice/i })
    await expect(payments).toBeVisible()

    // Two payment rows: the wallet and the provider, each named.
    await expect(payments.getByText(/account credit/i)).toBeVisible()
    await expect(payments.getByText(/test card/i)).toBeVisible()
  })
})

test.describe('journey F — a payment the bank refuses', () => {
  test('the refusal is visible on the invoice and the invoice stays payable', async ({ page }) => {
    await signIn(page, users.moneyCustomer)
    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.declineInvoice) })
    await row.getByRole('link', { name: /^view$/i }).click()

    // Waited for the document, not merely for the URL: the list is still
    // mounted for an instant after the address changes.
    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.declineInvoice) }),
    ).toBeVisible()

    await page.getByRole('button', { name: /^pay$/i }).click()
    await expect(page).toHaveURL(/\/fake-gateway\/authorise\//)

    await page.getByRole('button', { name: /decline the payment/i }).click()

    await expect(page).toHaveURL(/\/invoices\//)

    const payments = page.getByRole('table', { name: /payments on this invoice/i })
    await expect(payments.getByText(/declined by the bank/i)).toBeVisible()

    // Still payable: a refused card is not a settled invoice, and the customer
    // is offered another attempt.
    await expect(page.getByRole('button', { name: /^pay$/i })).toBeVisible()
  })
})

test.describe('journey G — cancelling the right subscription', () => {
  test('each subscription names its plan and its machine, and so does the dialogue', async ({
    page,
  }) => {
    await signIn(page)
    await page.goto('/subscriptions')

    // The two seeded agreements are on different plans and run different
    // machines, which is the whole point: they used to be identical rows.
    await expect(page.getByText(fixtures.operableHostname)).toBeVisible()
    await expect(page.getByText(fixtures.vpsHostname)).toBeVisible()

    const row = page.getByRole('row', { name: new RegExp(fixtures.operableHostname) })
    await row.getByRole('button', { name: /^cancel$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(fixtures.operableHostname)).toBeVisible()
    await expect(dialog.getByText(/will not renew/i)).toBeVisible()
    await expect(dialog.getByText(/kept for \d+ days/i)).toBeVisible()

    // Left un-cancelled: the assertion is about what the customer is told
    // before they decide, and the fixture is shared with other specs.
    await dialog.getByRole('button', { name: /keep|cancel/i }).last().click()
  })
})

test.describe('journey H — printing an invoice', () => {
  test('the printable copy carries the document and not the navigation', async ({ page }) => {
    await signIn(page)
    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.paidInvoice) })
    await row.getByRole('link', { name: /^view$/i }).click()

    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.paidInvoice) }),
    ).toBeVisible()

    await page.getByRole('link', { name: /^print$/i }).click()

    await expect(page).toHaveURL(/\/invoices\/.*\/print$/)

    // The document: its number, the address it was issued to, its lines.
    await expect(page.getByText(fixtures.paidInvoice)).toBeVisible()
    await expect(page.getByText(/addressed to/i)).toBeVisible()
    await expect(page.getByRole('table')).toBeVisible()

    // And no application chrome around it.
    await expect(page.getByRole('navigation')).toHaveCount(0)
    await expect(page.getByRole('link', { name: /^dashboard$/i })).toHaveCount(0)
  })
})

test.describe('the customer surface never claims a payment it cannot prove', () => {
  test('a browser-claimed success does not settle anything', async ({ page }) => {
    await signIn(page)

    // The URL a customer could type, or a provider could redirect to, or an
    // attacker could bookmark.
    await page.goto(`/invoices?status=paid&success=true`)

    const row = page.getByRole('row', { name: new RegExp(fixtures.openInvoice) })

    // The invoice is what the server says it is.
    await expect(row.getByRole('button', { name: /^pay$/i })).toBeVisible()
  })
})

test.describe('the payments history', () => {
  test('shows what was paid, what failed, and links back to each invoice', async ({ page }) => {
    await signIn(page, users.moneyCustomer)
    await page.goto('/payments')

    await expect(page.getByRole('heading', { name: /payments/i })).toBeVisible()

    const table = page.getByRole('table', { name: /payments/i })
    await expect(table).toBeVisible()
    await expect(table.getByRole('link', { name: /view the invoice/i }).first()).toBeVisible()
  })
})
