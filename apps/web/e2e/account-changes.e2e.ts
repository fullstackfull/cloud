import { expect, test, type Page } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * Changing what an account is billed in, across both sides.
 *
 * The customer asks on their dashboard; the operator decides on the
 * account-changes queue; the customer's dashboard then says the new
 * country. The spec asks for a country-only change (the seeded account
 * has an open invoice, so a currency change would be blocked — which the
 * API tests cover), and moves the account back at the end so the fixtures
 * are left as they were found — the account's country, and its inbox: each
 * applied change tells the customer, and the seeded inbox holds exactly one
 * unread notification for the specs that count it.
 */

// Two sessions, two round trips through an operator, and an inbox to tidy.
test.describe.configure({ timeout: 90_000 })

async function askFor(page: Page, country: string): Promise<void> {
  await page.goto('/')
  await page.getByRole('button', { name: /request a change/i }).click()
  const countryField = page.getByLabel(/country \(two letters\)/i)
  await countryField.fill(country)
  await page.getByLabel(/^why$/i).fill('Registered office moved.')
  await page.getByRole('button', { name: /send the request/i }).click()

  const change = page.getByTestId('country-currency-change')
  await expect(change).toBeVisible()
  await expect(change.getByText(/awaiting approval/i)).toBeVisible()
  // A country-only change is not blocked; it says what the tax becomes.
  await expect(change.getByRole('list', { name: /what this means/i })).toContainText(/tax/i)
}

async function approve(page: Page): Promise<void> {
  await page.goto('/admin/account-changes')
  const row = page.getByRole('row').filter({ hasText: /sample customer/i }).first()
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: /^approve$/i }).click()
  await page.getByLabel(/^note$/i).fill('Verified with the customer.')
  await page.getByRole('button', { name: /^confirm$/i }).click()
  await expect(page.getByRole('row').filter({ hasText: /sample customer/i })).toHaveCount(0)
}

test('a country change is asked for by the customer, approved by an operator, and applied without touching anything issued', async ({
  browser,
}) => {
  const customer = await browser.newContext()
  const customerPage = await customer.newPage()
  await signIn(customerPage, users.customer)

  await expect(customerPage.getByTestId('billed-in')).toHaveText(/KWD · KW/)
  await askFor(customerPage, 'SA')

  const operator = await browser.newContext()
  const operatorPage = await operator.newPage()
  await signIn(operatorPage, users.operator)
  await approve(operatorPage)

  await customerPage.reload()
  await expect(customerPage.getByTestId('billed-in')).toHaveText(/KWD · SA/)
  await expect(customerPage.getByText(/^applied$/i).first()).toBeVisible()

  // The open invoice the seeder wrote is exactly as it was.
  await customerPage.goto('/invoices')
  await expect(customerPage.getByText('INV-E2E-0001')).toBeVisible()

  // And back, so the next run finds the account where this one did.
  await askFor(customerPage, 'KW')
  await approve(operatorPage)
  await customerPage.reload()
  await expect(customerPage.getByTestId('billed-in')).toHaveText(/KWD · KW/)

  // The two notifications this spec raised, read; the seeded unread one, not.
  await customerPage.goto('/notifications')
  const raised = customerPage.getByRole('listitem').filter({ hasText: /now billed in/i })
  await expect(raised).toHaveCount(2)
  const unread = raised.getByRole('button', { name: /^mark read$/i })
  await expect(unread).toHaveCount(2)
  await unread.first().click()
  await expect(unread).toHaveCount(1)
  await unread.first().click()
  await expect(unread).toHaveCount(0)
  await expect(customerPage.getByText(/1 unread/i)).toBeVisible()

  await customer.close()
  await operator.close()
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the section reads in Arabic and keeps the currency code unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/')

    await expect(page.getByText('البلد والعملة')).toBeVisible()
    await expect(page.getByText(/لا يُحوَّل شيء صدر من قبل/)).toBeVisible()

    const billedIn = page.getByTestId('billed-in')
    expect(await billedIn.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')
    await expect(page.getByRole('button', { name: 'طلب تغيير' })).toBeVisible()
  })
})
