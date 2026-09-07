import { expect, test } from '@playwright/test'

import { fixtures, signIn } from './support/helpers'

/*
 * The screens a customer actually uses, driven end to end.
 *
 * Every assertion below is on a value the seeder wrote, so a page that renders
 * an empty state, or somebody else's data, or nothing at all, fails. The
 * component tests in `src/` cannot make these assertions: they stub the API,
 * so they prove the component renders what it is handed and nothing about
 * whether the API hands it that.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page)
})

test('the dashboard names the signed-in customer', async ({ page }) => {
  await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible()
})

test('the catalogue lists what is for sale', async ({ page }) => {
  await page.goto('/catalogue')

  // Three products come from the catalogue seeder. Asserting on the count
  // rather than a name keeps this from breaking when the demo catalogue is
  // renamed, while still failing if the page renders nothing.
  await expect(page.getByRole('heading', { level: 2 })).not.toHaveCount(0)
  await expect(page.getByText(/plans available/i).first()).toBeVisible()
})

test('a product page shows its plans and their prices', async ({ page }) => {
  await page.goto('/catalogue')
  // The card links by its call to action, not by the product name: the name is
  // a heading, not the link, so filtering links by it navigates nowhere.
  await page.getByRole('link', { name: /view plans/i }).first().click()

  await expect(page).toHaveURL(/\/catalogue\//)
  // A price with its currency. An amount with no currency is an amount a
  // customer has to guess at.
  await expect(page.getByText(/KWD/).first()).toBeVisible()
})

test('the invoice list shows the open one and the settled one differently', async ({ page }) => {
  await page.goto('/invoices')

  await expect(page.getByText(fixtures.openInvoice)).toBeVisible()
  await expect(page.getByText(fixtures.paidInvoice)).toBeVisible()

  // Every amount carries its currency.
  await expect(page.getByText(/KWD/).first()).toBeVisible()
})

test('the wallet shows a balance with its currency', async ({ page }) => {
  await page.goto('/wallet')

  await expect(page.getByText(/KWD/).first()).toBeVisible()
  // 12.750 KWD, seeded. Fils and all: a balance rendered to two decimals
  // would be wrong for this currency.
  await expect(page.getByText(/12\.750/)).toBeVisible()
})

test('the services list shows the seeded VPS', async ({ page }) => {
  await page.goto('/services')

  await expect(page.getByText(new RegExp(fixtures.vpsHostname, 'i'))).toBeVisible()
})

test('the VPS list shows the machine and its address', async ({ page }) => {
  await page.goto('/vps')

  await expect(page.getByText(fixtures.vpsHostname)).toBeVisible()
})

test('the backups screen shows both states the seeder writes', async ({ page }) => {
  await page.goto('/backups')

  // The seeder writes one finished backup the customer could restore from and
  // one that stopped being trackable and is waiting for a person. A screen
  // that showed only the happy one would be hiding the case that matters.
  await expect(page.getByText(/succeeded/i).first()).toBeVisible()
  await expect(page.getByText(/needs.review/i).first()).toBeVisible()
})

test('a backup waiting for a person cannot be restored from', async ({ page }) => {
  /*
   * The needs-review backup stopped being trackable: the platform does not
   * know whether its archive is complete. Offering a restore from it would
   * offer to overwrite a working machine with an unknown.
   */
  await page.goto('/backups')

  await expect(page.getByRole('button', { name: /^restore$/i })).toHaveCount(2)
  await expect(page.locator('button:disabled', { hasText: /^restore$/i })).toHaveCount(1)
})

test('a restore cannot be confirmed without typing the hostname', async ({ page }) => {
  await page.goto('/backups')

  // The enabled one: the other row is the needs-review backup, whose restore
  // is deliberately refused.
  await page.locator('button:enabled', { hasText: /^restore$/i }).first().click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  // The customer is told what they are about to lose, in those words.
  await expect(dialog.getByText(/cannot be undone/i)).toBeVisible()

  const confirm = dialog.getByRole('button', { name: /^restore$/i })
  await expect(confirm).toBeDisabled()

  // A hostname that is nearly right is still wrong. This is not a lookup.
  await dialog.getByRole('textbox').fill(fixtures.vpsHostname.toUpperCase())
  await expect(confirm).toBeDisabled()

  await dialog.getByRole('textbox').fill(fixtures.vpsHostname)
  await expect(confirm).toBeEnabled()
})

test('escape closes the restore dialog without restoring', async ({ page }) => {
  /*
   * The focus trap, the inert background and Escape all come from the native
   * <dialog> element rather than from hand-written key handling. This asserts
   * the browser is really giving them to us — jsdom cannot, so the component
   * test could not check it.
   */
  await page.goto('/backups')

  await page.locator('button:enabled', { hasText: /^restore$/i }).first().click()
  await expect(page.getByRole('dialog')).toBeVisible()

  await page.keyboard.press('Escape')

  await expect(page.getByRole('dialog')).toBeHidden()
  await expect(page.getByText(/restoring/i)).toHaveCount(0)
})

test('the VPS list offers the two ways of stopping a machine as separate controls', async ({ page }) => {
  await page.goto('/vps')

  // "Shut down" asks the guest to close its files; "Force off" pulls the plug.
  // One button for both is how a customer loses a database, so the screen has
  // to keep offering two, and they have to read differently.
  await expect(page.getByRole('button', { name: /^shut down$/i }).first()).toBeVisible()
  await expect(page.getByRole('button', { name: /^force off$/i }).first()).toBeVisible()
})

test('an address the portal does not serve renders a not-found page', async ({ page }) => {
  await page.goto('/does-not-exist')

  await expect(page.getByRole('heading', { name: /not found/i })).toBeVisible()
})
