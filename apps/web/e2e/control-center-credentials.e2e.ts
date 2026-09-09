import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The credential screen: where a secret is, never what it is.
 *
 * Proven in PHPUnit: the API stores references, refuses values, and hides the
 * reference on the way out. What the browser adds is the operator's side of
 * that — that the warning is on the form before they type, that a pasted
 * value produces a refusal telling them to rotate it, and that "present" and
 * "missing" are two different things on the screen in both languages.
 *
 * Fixtures come from E2ESeeder::credentials and the variable the API process
 * is given in playwright.config.ts.
 */

function row(page: Page, name: string) {
  return page.getByRole('row').filter({ hasText: name })
}

test.describe('an operator managing credentials', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/credentials')
    await expect(page.getByRole('heading', { name: /^credentials$/i })).toBeVisible()
  })

  test('sees which credentials the controller has and which it does not, and never a reference', async ({
    page,
  }) => {
    await expect(row(page, fixtures.credentialPresent)).toContainText(/on controller/i)
    await expect(row(page, fixtures.credentialPresent)).toContainText(/configured/i)

    await expect(row(page, fixtures.credentialMissing)).toContainText(/not on controller/i)
    await expect(row(page, fixtures.credentialMissing)).toContainText(/missing/i)

    // The variable name is the reference; it is hidden on the model and this
    // screen never asks for it. The hint is shown, because that is a public
    // identifier's tail and the reason it exists.
    await expect(page.getByText(fixtures.credentialPresentReference)).toHaveCount(0)
    await expect(row(page, fixtures.credentialPresent)).toContainText('Q7X2')
  })

  test('is warned before typing, and told to rotate if a value is pasted anyway', async ({ page }) => {
    await page.getByRole('button', { name: /record a credential/i }).click()

    const form = page.getByRole('form', { name: /record where a credential lives/i })
    await expect(form.getByText(/do not paste the secret/i)).toBeVisible()

    await form.getByLabel(/^name$/i).fill(`e2e-pasted-${Date.now().toString(36)}`)
    await form.getByLabel(/purpose/i).fill('Mistake')
    await form.getByLabel(/variable name on the controller/i).fill('sk-live-pasted-by-accident')
    await form.getByRole('button', { name: /^record$/i }).click()

    await expect(form.getByText(/rotate it now/i)).toBeVisible()
    await expect(form.getByText(/sk-live-pasted-by-accident/)).toHaveCount(0)
  })

  test('can record a reference the controller does not have yet, which shows as missing', async ({
    page,
  }) => {
    const name = `e2e-recorded-${Date.now().toString(36)}`

    await page.getByRole('button', { name: /record a credential/i }).click()
    const form = page.getByRole('form', { name: /record where a credential lives/i })

    await form.getByLabel(/^name$/i).fill(name)
    await form.getByLabel(/purpose/i).fill('Backup server token')
    await form.getByLabel(/variable name on the controller/i).fill('lynomia_e2e_recorded_token')
    await form.getByRole('button', { name: /^record$/i }).click()

    await expect(row(page, name)).toBeVisible()
    await expect(row(page, name)).toContainText(/missing/i)
    await expect(row(page, name)).toContainText(/not on controller/i)
  })

  test('revokes only with a reason, and the row says so afterwards', async ({ page }) => {
    // The seeded missing credential is the one this spec consumes; nothing
    // else asserts on its state.
    await row(page, fixtures.credentialMissing).getByRole('button', { name: /^revoke$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: /^revoke$/i })).toBeDisabled()

    await dialog.getByLabel(/why\?/i).fill('Chassis returned to the vendor.')
    await dialog.getByRole('button', { name: /^revoke$/i }).click()

    await expect(row(page, fixtures.credentialMissing)).toContainText(/revoked/i)
    await expect(row(page, fixtures.credentialMissing)).toContainText(/chassis returned to the vendor/i)
    await expect(row(page, fixtures.credentialMissing).getByRole('button', { name: /^revoke$/i })).toHaveCount(0)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the credential screen reads in Arabic and keeps names unmirrored', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/credentials')

    await expect(page.getByRole('heading', { name: /الاعتمادات/ })).toBeVisible()
    await expect(row(page, fixtures.credentialPresent)).toContainText(/موجود على المتحكّم/)
    await expect(row(page, fixtures.credentialPresent)).toContainText(/مُعدّ/)

    // The name cell is left-to-right inside a right-to-left page.
    const name = row(page, fixtures.credentialPresent).locator('.technical').first()
    await expect(name).toHaveText(fixtures.credentialPresent)
  })
})
