import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The team screen.
 *
 * What makes it worth driving in a browser rather than only in the API tests:
 * every control on it is conditional on a role, and a screen that offers a
 * button the server will refuse is worse than one that offers nothing — the
 * customer presses it, gets a 403, and learns nothing about why.
 *
 * The seeded account has two people in it, so a list, a role change and a
 * removal are all reachable; and one invitation nobody has answered, so the
 * invitation table is not empty.
 */

test.describe('as the owner', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('the team screen lists everybody with access and what each of them may do', async ({
    page,
  }) => {
    await page.goto('/settings/team')

    await expect(page.getByRole('heading', { name: /^team$/i })).toBeVisible()
    await expect(page.getByText(users.customer.email)).toBeVisible()
    await expect(page.getByText(fixtures.teammateEmail)).toBeVisible()

    // The owner's role is shown and is not editable — an account has one owner,
    // and ownership moves by transfer rather than by a dropdown.
    const ownerRow = page
      .getByRole('row')
      .filter({ hasText: users.customer.email })
    await expect(ownerRow.getByText(/^owner$/i)).toBeVisible()
    await expect(ownerRow.getByRole('combobox')).toHaveCount(0)
  })

  test('a colleague can be moved between roles from the list', async ({
    page,
  }) => {
    await page.goto('/settings/team')

    const teammateRow = page
      .getByRole('row')
      .filter({ hasText: fixtures.teammateEmail })
    const role = teammateRow.getByRole('combobox')

    await expect(role).toHaveValue('technical')
    await role.selectOption('billing')

    // Reloaded rather than asserted on the optimistic value: the point is that
    // the server took it, not that the select changed.
    await page.reload()
    await expect(
      page
        .getByRole('row')
        .filter({ hasText: fixtures.teammateEmail })
        .getByRole('combobox'),
    ).toHaveValue('billing')

    await page
      .getByRole('row')
      .filter({ hasText: fixtures.teammateEmail })
      .getByRole('combobox')
      .selectOption('technical')
  })

  test('the invitation form says what the offer is worth before it is sent', async ({
    page,
  }) => {
    await page.goto('/settings/team')

    await expect(
      page.getByRole('heading', { name: /invite somebody/i }),
    ).toBeVisible()

    // The hint under the role selector changes with the role, because 'Billing'
    // and 'Technical' mean nothing to somebody deciding which one a colleague
    // should have.
    await page.getByRole('combobox').last().selectOption('technical')
    await expect(page.getByText(/no access to money/i)).toBeVisible()

    await page.getByRole('combobox').last().selectOption('billing')
    await expect(
      page.getByText(/cannot change them|not change them/i),
    ).toBeVisible()
  })

  test('an invitation appears in the list and can be withdrawn', async ({
    page,
  }) => {
    await page.goto('/settings/team')

    await page
      .getByLabel(/email address/i)
      .first()
      .fill('e2e-invited@example.test')
    await page.getByRole('button', { name: /send invitation/i }).click()

    const row = page
      .getByRole('row')
      .filter({ hasText: 'e2e-invited@example.test' })
    await expect(row).toBeVisible()
    await expect(row.getByText(/waiting/i)).toBeVisible()

    await row.getByRole('button', { name: /withdraw/i }).click()

    // Withdrawing asks first, naming the address; escape leaves the offer open.
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('e2e-invited@example.test')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(row.getByText(/waiting/i)).toBeVisible()

    await row.getByRole('button', { name: /withdraw/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^withdraw invitation$/i }).click()
    await expect(
      page
        .getByRole('row')
        .filter({ hasText: 'e2e-invited@example.test' })
        .getByText(/withdrawn/i),
    ).toBeVisible()
  })

  test('the seeded pending invitation is listed without anything that could redeem it', async ({
    page,
  }) => {
    await page.goto('/settings/team')

    const row = page
      .getByRole('row')
      .filter({ hasText: fixtures.pendingInvitationEmail })
    await expect(row).toBeVisible()

    // The token exists only in the mail. A team screen that carried one would let
    // anybody who can read the screen join as anybody who has been invited.
    // Not 'the word token does not appear' — a development page carries Vite's
    // own scripts and the word means nothing there. What must not appear is
    // something that *works*: 64 hexadecimal characters, which is exactly the
    // shape of the value the accept endpoint takes.
    const html = await page.content()
    expect(html).not.toMatch(/\b[0-9a-f]{64}\b/)
  })
})

test.describe('as a colleague rather than the owner', () => {
  test('a technical member sees who has access and cannot change it', async ({
    page,
  }) => {
    await signIn(page, { email: fixtures.teammateEmail, password: 'password' })
    await page.goto('/settings/team')

    // Seeing the list is deliberate: somebody who cannot tell who has access to
    // their servers is worse off than somebody who can.
    await expect(page.getByText(users.customer.email)).toBeVisible()

    // Nothing that writes.
    await expect(
      page.getByRole('heading', { name: /invite somebody/i }),
    ).toHaveCount(0)
    await expect(page.getByRole('button', { name: /remove/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /make owner/i })).toHaveCount(
      0,
    )
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the team screen and its role descriptions read in Arabic, right to left', async ({
    page,
  }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/settings/team')

    await expect(page.getByRole('heading', { name: 'الفريق' })).toBeVisible()
    await expect(page.getByText('المالك').first()).toBeVisible()

    // The role hints are the copy a person actually decides on. Untranslated,
    // the screen asks an Arabic reader to choose between five English words.
    await page.getByRole('combobox').last().selectOption('technical')
    await expect(page.getByText(/لا وصول إلى المال/)).toBeVisible()

    const direction = await page.evaluate(() => document.documentElement.dir)
    expect(direction).toBe('rtl')

    // An email address is not mirrored: an address read right to left is a
    // different address.
    const address = page.getByText(fixtures.teammateEmail).first()
    expect(
      await address.evaluate((node) => getComputedStyle(node).direction),
    ).toBe('ltr')
  })
})
