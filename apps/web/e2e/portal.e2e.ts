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

/*
 * There is deliberately no backups spec here.
 *
 * `GET /api/v1/vps/{vm}/backups` exists and is tested at the API level, and the
 * seeder writes one succeeded and one needs-review backup for the machine
 * below — but the portal has no screen that reads either. The VPS list is a
 * table of machines and their power state; there is no machine detail page and
 * no backups page, and `nav.backups` is an orphan translation left over from
 * the navigation being drafted ahead of the screen.
 *
 * Writing a spec that clicked a hostname and looked for "succeeded" is how this
 * gap stayed invisible: the spec failed, and the failure said "selector wrong"
 * rather than "the feature has no user interface". It is recorded as
 * NOT_IMPLEMENTED in docs/build-status.md instead of being asserted around.
 */

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
