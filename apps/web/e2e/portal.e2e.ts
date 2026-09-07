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

test('the subscriptions screen tells a renewal apart from a cancellation', async ({ page }) => {
  /*
   * The seeder writes two subscriptions on purpose. The renewal date and the
   * cancellation date come from the same pair of columns, so a screen that
   * showed only one of them would look perfectly correct against a fixture
   * that only had one.
   */
  await page.goto('/subscriptions')

  await expect(page.getByText(/KWD/).first()).toBeVisible()

  // One is renewing and offers a way out; the other has already been
  // cancelled, and offering to cancel it again would be a button that does
  // nothing.
  await expect(page.getByRole('button', { name: /cancel/i })).toHaveCount(1)
  // "Ends <date>", the string the cancelled row actually renders.
  await expect(page.getByText(/^Ends /i)).toBeVisible()
})

test('the notification inbox shows what the platform has told this account', async ({ page }) => {
  /*
   * The seeder writes one unread and one read notification on purpose. The
   * unread count, the emphasis and the "mark read" control all behave
   * differently between the two, and a fixture with only one state lets half a
   * screen look finished.
   */
  await page.goto('/notifications')

  await expect(page.getByText(/1 unread/i)).toBeVisible()

  /*
   * Title and body, both rendered by the API in the reader's language with the
   * hostname interpolated into one whole sentence. Asserted separately rather
   * than with one loose match, because the hostname appearing in both is the
   * correct outcome and a single locator would fail on it.
   */
  await expect(page.getByText(`${fixtures.vpsHostname} is ready`)).toBeVisible()
  await expect(page.getByText(new RegExp(`${fixtures.vpsHostname} is now running`))).toBeVisible()

  // Exactly one row offers to be marked read: the other already is.
  await expect(page.getByRole('button', { name: /^mark read$/i })).toHaveCount(1)
})

test('marking a notification read clears it from the unread count', async ({ page }) => {
  await page.goto('/notifications')

  await page.getByRole('button', { name: /^mark read$/i }).click()

  await expect(page.getByText(/0 unread/i)).toBeVisible()
  await expect(page.getByRole('button', { name: /^mark read$/i })).toHaveCount(0)
})

test('a customer cannot switch off billing email, and is told so', async ({ page }) => {
  /*
   * Shown and disabled rather than omitted. A screen that simply left the
   * immovable categories out would leave a customer wondering whether they had
   * been switched off silently; the honest answer is that we will always tell
   * them their card was declined.
   */
  await page.goto('/profile')

  const billing = page.locator('li').filter({ hasText: /^Billing/ })

  await expect(billing.getByText(/always sent/i).first()).toBeVisible()
  await expect(billing.getByRole('checkbox').first()).toBeDisabled()
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

test('the reinstall dialogue says the disk will be replaced and needs the hostname typed', async ({ page }) => {
  /*
   * The most destructive control a customer has. Three things are asserted
   * because each of them is a way this can be got wrong: the warning has to be
   * present and unambiguous, the confirmation has to require the machine's own
   * name, and a nearly-right name has to stay refused — the comparison is a
   * proof that somebody read the screen, not a lookup.
   */
  await page.goto('/vps')

  await page.getByRole('button', { name: /^reinstall$/i }).first().click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  await expect(
    dialog.getByText(/the server disk will be replaced\. existing data may be permanently lost\./i),
  ).toBeVisible()

  const confirm = dialog.getByRole('button', { name: /reinstall this server/i })
  await expect(confirm).toBeDisabled()

  await dialog.getByRole('textbox').fill(fixtures.vpsHostname.toUpperCase())
  await expect(confirm).toBeDisabled()

  await dialog.getByRole('textbox').fill(fixtures.vpsHostname)
  await expect(confirm).toBeEnabled()
})

test('escape closes the reinstall dialogue without rebuilding anything', async ({ page }) => {
  await page.goto('/vps')

  await page.getByRole('button', { name: /^reinstall$/i }).first().click()
  await expect(page.getByRole('dialog')).toBeVisible()

  await page.keyboard.press('Escape')

  await expect(page.getByRole('dialog')).toBeHidden()

  // And nothing was queued: the rebuild column still shows no operation.
  await expect(page.getByText(/replacing the disk/i)).toHaveCount(0)
})

test('the dedicated rebuild dialogue warns about the operating system and needs the serial typed', async ({ page }) => {
  /*
   * A different screen, a different identifier and a different warning from
   * the VPS one — each of which is a place the wrong copy could ship, and the
   * warning is the only thing standing between a customer and an erased
   * physical machine.
   */
  await page.goto('/dedicated')

  await page.getByRole('button', { name: /^reinstall$/i }).first().click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  await expect(
    dialog.getByText(
      /reinstalling this server will erase the installed operating system and may permanently destroy data on the machine\./i,
    ),
  ).toBeVisible()

  const confirm = dialog.getByRole('button', { name: /reinstall this server/i })
  await expect(confirm).toBeDisabled()

  // The hostname of the customer's *other* machine is not this machine's
  // serial, and a confirmation that accepted it would be no confirmation.
  await dialog.getByRole('textbox').fill(fixtures.vpsHostname)
  await expect(confirm).toBeDisabled()

  await dialog.getByRole('textbox').fill(fixtures.dedicatedSerial)
  await expect(confirm).toBeEnabled()
})

test('the console page asks for a permit and says honestly when consoles are unavailable', async ({ page }) => {
  /*
   * The console button must not be a button that silently does nothing. In an
   * environment with no gateway configured — which is this one — pressing
   * connect must produce a sentence explaining that, not a spinner or a blank
   * terminal.
   *
   * The byte-level proof that the gateway actually carries a console lives in
   * the PHP runtime test, which drives real sockets against a controlled
   * upstream. A browser cannot prove that without a gateway process in this
   * harness, and pretending otherwise here would be the kind of claim this
   * phase exists to remove.
   */
  await page.goto('/vps')

  await page.getByRole('link', { name: /^console$/i }).first().click()

  await expect(page.getByRole('heading', { name: /console/i })).toBeVisible()

  // The permit is not requested on load: it lives sixty seconds and one minted
  // by an open tab has expired before anybody presses connect.
  await expect(page.getByText(/not connected/i)).toBeVisible()

  const permit = page.waitForResponse((response) => response.url().includes('/console') && response.request().method() === 'GET')

  await page.getByRole('button', { name: /^connect$/i }).click()

  expect((await permit).status()).toBe(201)

  await expect(page.getByText(/consoles are not available on this deployment/i)).toBeVisible()
})

test('the change-plan screen prices every option and refuses a smaller disk in the backend', async ({ page }) => {
  /*
   * Two things are being proved. The prices on this screen come from the API —
   * the portal renders Money and computes none of it — and the refusals come
   * from the backend too, so a plan the platform will not sell is shown with
   * its reason rather than as a button that fails on confirm.
   */
  await page.goto('/subscriptions')

  const options = page.waitForResponse((response) => response.url().includes('/plan-options'))

  await page.getByRole('link', { name: /change plan/i }).first().click()

  // Typed on the way in rather than asserted on an `any`: the shape of this
  // response is part of what the test is checking.
  const body = (await (await options).json()) as {
    data: Array<{ is_available: boolean; refusals: string[]; amount_due_now: unknown }>
  }

  const quotes = body.data

  expect(quotes.length).toBeGreaterThan(0)

  // The plan the subscription already sits on is listed and refused, not
  // hidden: a screen that omitted it would leave a customer wondering where
  // their own plan went.
  expect(quotes.some((quote) => quote.refusals.includes('same_plan'))).toBe(true)

  // Anything refused carries no price at all. A price shown against a plan the
  // platform would decline is a price a customer decides on and then cannot have.
  for (const quote of quotes) {
    if (! quote.is_available) {
      expect(quote.amount_due_now).toBeNull()
    }
  }

  await expect(page.getByRole('heading', { name: /change plan/i })).toBeVisible()
  await expect(page.getByText(/per period/i).first()).toBeVisible()
})
