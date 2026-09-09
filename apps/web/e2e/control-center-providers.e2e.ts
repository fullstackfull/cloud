import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The provider screen: registered is not enabled, and readiness says why.
 *
 * Proven in PHPUnit: readiness blames one blocker in dependency order, a
 * provider cannot be enabled on hope, and a connection test records what the
 * account can do. What the browser adds is the walk an operator takes: read
 * the blocker in words, test, watch capabilities appear, enable, and disable
 * with a reason that is then shown.
 *
 * Fixtures from E2ESeeder::machinesAndProviders: e2e-dns has no credential and
 * is blocked on it; e2e-bmc-node-01 has one the controller holds.
 */

// By the name cell exactly, not by substring: `e2e-dns` is a prefix of the
// provider the registration spec creates, and a substring filter would find both.
function row(page: Page, name: string) {
  return page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) })
}

test.describe('an operator looking after providers', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/providers')
    await expect(page.getByRole('heading', { name: /^providers$/i })).toBeVisible()
  })

  test('reads the first blocker and what to do about it, in words', async ({ page }) => {
    await expect(row(page, fixtures.providerBlocked)).toContainText(/not ready/i)
    await expect(row(page, fixtures.providerBlocked)).toContainText(/blocked: credentials/i)
    await expect(row(page, fixtures.providerBlocked)).toContainText(/attach a credential the controller holds/i)
    await expect(page.getByText(/controlCenter\.guidance|blocked_credentials/)).toHaveCount(0)

    await row(page, fixtures.providerBlocked).getByRole('button', { name: /^open$/i }).click()
    await expect(page.getByRole('button', { name: /^enable$/i })).toBeDisabled()
  })

  test('tests a provider, sees its capabilities, enables it, and disables it with a reason', async ({ page }) => {
    await row(page, fixtures.providerTestable).getByRole('button', { name: /^open$/i }).click()

    await expect(page.getByText(/nothing observed yet/i)).toBeVisible()
    await page.getByRole('button', { name: /test and discover/i }).click()

    // The fake BMC answers every capability its category is asked about.
    const capabilities = page.getByRole('list', { name: /^capabilities$/i })
    await expect(capabilities.getByText('power_control')).toBeVisible()
    await expect(capabilities.getByText(/^supported$/i).first()).toBeVisible()

    // A usable connection plus discovered capabilities is ready-for-production,
    // and only then is Enable pressable.
    await expect(row(page, fixtures.providerTestable)).toContainText(/ready for production/i)
    const enable = page.getByRole('button', { name: /^enable$/i })
    await expect(enable).toBeEnabled()
    await enable.click()

    await expect(row(page, fixtures.providerTestable)).toContainText(/^(?=.*enabled)(?=.*serving)/i)

    await page.getByRole('button', { name: /^disable$/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: /^disable$/i })).toBeDisabled()
    await dialog.getByLabel(/why\?/i).fill('Vendor maintenance window tonight.')
    await dialog.getByRole('button', { name: /^disable$/i }).click()

    await expect(row(page, fixtures.providerTestable)).toContainText(/disabled/i)
    await expect(page.getByText(/disabled: vendor maintenance window tonight/i)).toBeVisible()
  })

  test('registers a provider from the catalogue, which is assessed on arrival', async ({ page }) => {
    const name = `e2e-dns-${Date.now().toString(36)}`

    await page.getByRole('button', { name: /register a provider/i }).click()
    const form = page.getByRole('form', { name: /register a provider/i })

    await form.getByLabel(/^driver$/i).selectOption('fake')
    await expect(form.getByText(/controlled provider for rehearsing/i)).toBeVisible()
    await form.getByLabel(/^name$/i).fill(name)
    await form.getByLabel(/endpoint/i).fill('fake://connected')
    await form.getByRole('button', { name: /^register$/i }).click()

    // Registered and immediately assessed: no credential yet, so the row
    // already says what is missing rather than a hopeful "draft".
    await expect(row(page, name)).toContainText(/not ready/i)
    await expect(row(page, name)).toContainText(/blocked: credentials/i)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the provider screen reads in Arabic and keeps driver names unmirrored', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/providers')

    await expect(page.getByRole('heading', { name: /المزوّدون/ })).toBeVisible()
    await expect(row(page, fixtures.providerBlocked)).toContainText(/محجوب: الاعتماد/)
    await expect(row(page, fixtures.providerBlocked)).toContainText(/غير جاهز/)

    const name = row(page, fixtures.providerBlocked).locator('.technical').first()
    await expect(name).toHaveText(fixtures.providerBlocked)
  })
})
