import { expect, test, type Page } from '@playwright/test'

import { expectAccessible } from './support/axe'
import { fixtures, raiseNotification, signIn, users } from './support/helpers'

/*
 * Wave 5, W5.6. The automated floor under the manual accessibility work.
 *
 * Ten screens, chosen to cover every distinct shape the portal has rather than
 * ten variations of a table: the dashboard's attention list, a long activity
 * feed, a resource page with sections and destructive controls, a money
 * document, a domain with provider-backed facts, a form-heavy DNS screen, the
 * role matrix, the security page's two tables, the token form, and support.
 *
 * What a clean run here does **not** mean is stated in `support/axe.ts` and
 * bears repeating: axe checks what a machine can check. It cannot tell whether
 * the tab order matches the reading order, whether focus lands somewhere
 * sensible after a dialogue closes, or whether an Arabic screen reader makes
 * sense of a name assembled from two strings. Those are the journeys in
 * `wave-5-keyboard.e2e.ts`, and that is where this wave's real defects came
 * from. Nothing here claims conformance with anything.
 */

async function openOperableMachine(page: Page): Promise<void> {
  await page.goto('/vps')
  await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
}

test.describe('the automated accessibility floor', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('the dashboard', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'dashboard')
  })

  test('the activity feed', async ({ page }) => {
    await page.goto('/activity')
    await expect(page.getByRole('heading', { level: 1, name: 'Activity' })).toBeVisible()

    await expectAccessible(page, 'activity')
  })

  test('a VPS detail page, with its sections and controls', async ({ page }) => {
    await openOperableMachine(page)

    await expectAccessible(page, 'vps detail')
  })

  test('an invoice', async ({ page }) => {
    await page.goto('/invoices')

    // The invoice number is text in its row; the link is the action beside it.
    await page.getByRole('link', { name: `View invoice ${fixtures.openInvoice}` }).click()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'invoice detail')
  })

  test('a domain', async ({ page }) => {
    await page.goto('/domains')
    await page.getByRole('link', { name: fixtures.heldDomain }).first().click()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'domain detail')
  })

  test('the DNS screen', async ({ page }) => {
    await page.goto('/dns')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'dns')
  })

  test('the team screen and its role matrix', async ({ page }) => {
    await page.goto('/settings/team')

    // The matrix is the densest table in the portal: nine capability rows
    // against five roles, every cell carrying a word as well as a glyph.
    await expect(page.getByRole('table').first()).toBeVisible()

    await expectAccessible(page, 'team')
  })

  test('the security page, its sessions and its sign-in history', async ({ page }) => {
    await page.goto('/security')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'security')
  })

  test('the API tokens page and its form', async ({ page }) => {
    await page.goto('/api-tokens')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'api tokens')
  })

  test('support, with an open request', async ({ page }) => {
    await page.goto('/support')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'support')
  })

  test('names the notification link with its count, as a screen reader hears it', async ({
    page,
  }) => {
    /*
     * The exact accessible name, asserted in the only place it can honestly be
     * asserted: `getByRole(..., { name })` here resolves through Chromium's own
     * accessibility tree, which is what a screen reader reads. The unit test
     * beside this one matches loosely, because jsdom joins adjacent text nodes
     * without the space Chromium inserts — and pinning the unit test to jsdom's
     * answer is exactly how this badge came to carry a hard-coded comma that
     * made the real name "Notifications , 1 unread".
     *
     * The unread message is this spec's own. The seeder writes exactly one,
     * and the Wave 4 spec marks everything read to prove the badge clears —
     * so borrowing it made this assertion depend on which specs had run, and
     * in a full suite the count was zero and the name had no number in it at
     * all. An exact name needs a known number.
     */
    const unread = raiseNotification()

    await page.goto('/')

    await expect(
      page
        .getByRole('navigation', { name: /main navigation/i })
        .getByRole('link', { name: `Notifications ${String(unread)} unread` }),
    ).toBeVisible()
  })

  test('gives each row action a name that says which row it acts on', async ({ page }) => {
    /*
     * "View" is enough to read and not enough to hear. A page of ten invoices
     * offered ten links all named "View": the column header that tells them
     * apart visually is not part of a link's accessible name, so somebody
     * listing the links heard "View, View, View…".
     */
    await page.goto('/invoices')

    await expect(
      page.getByRole('link', { name: `View invoice ${fixtures.openInvoice}` }),
    ).toBeVisible()

    // And the visible word stays short, because the column says the rest.
    await expect(page.getByRole('link', { name: /^View$/ })).toHaveCount(0)
  })

  test('the profile, with its selectors', async ({ page }) => {
    // Not on the required list, but it is where the time-zone and language
    // selectors and the notification switches live — three control types that
    // exist nowhere else.
    await page.goto('/profile')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'profile')
  })
})

test.describe('the automated floor, in Arabic', () => {
  test('the dashboard reads right to left without losing its semantics', async ({ page }) => {
    /*
     * Run in Arabic as well, because several of the rules axe checks are
     * direction-sensitive in practice: a control whose accessible name is
     * assembled from two strings can come out backwards, and a technical
     * identifier forced to LTR inside an RTL page is exactly the kind of thing
     * that ends up with no name at all.
     */
    await signIn(page, users.customer)

    await page.getByRole('button', { name: 'العربية' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    await expectAccessible(page, 'dashboard (Arabic)')
  })
})
