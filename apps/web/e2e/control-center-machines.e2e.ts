import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The machine screen: what kind of machine, whether it may be reached, and
 * what a real look found.
 *
 * Proven in PHPUnit: the API refuses to connect to a do-not-touch machine,
 * derives the driver from the machine's own BMC provider, and records facts
 * with their source. What the browser adds is that the refusal is visible
 * before the click, that the classification ladder is climbed one rung at a
 * time through the form, and that a discovery's facts reach the screen.
 *
 * Fixtures from E2ESeeder::machinesAndProviders: e2e-node-01 is discovery-only
 * with a working fake BMC; e2e-node-02 is do-not-touch with nothing bound.
 */

// By the name cell exactly, not by substring: `e2e-dns` is a prefix of the
// provider the registration spec creates, and a substring filter would find both.
function row(page: Page, name: string) {
  return page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) })
}

test.describe('an operator looking after machines', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/machines')
    await expect(page.getByRole('heading', { name: /^machines$/i })).toBeVisible()
  })

  test('sees each classification in words, and that a do-not-touch machine cannot be reached', async ({ page }) => {
    await expect(row(page, fixtures.machineReachable)).toContainText(/discovery only/i)
    await expect(row(page, fixtures.machineUntouched)).toContainText(/do not touch/i)
    await expect(page.getByText(/do_not_touch|discovery_only/)).toHaveCount(0)

    await row(page, fixtures.machineUntouched).getByRole('button', { name: /^open$/i }).click()
    await expect(page.getByText(/may not be connected to, even to test/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /test connection/i })).toBeDisabled()
    await expect(page.getByRole('button', { name: /^discover$/i })).toBeDisabled()
    await expect(page.getByText(/never classified/i)).toBeVisible()
  })

  test('reaches a discovery-only machine through its BMC and sees what was found', async ({ page }) => {
    await row(page, fixtures.machineReachable).getByRole('button', { name: /^open$/i }).click()

    await page.getByRole('button', { name: /test connection/i }).click()
    // The result badge, and the steps the fake tester walked: the operator
    // sees which step passed, not just that something did.
    await expect(page.getByText(/✓ authenticate/)).toBeVisible()

    await page.getByRole('button', { name: /^discover$/i }).click()
    await expect(page.getByText(/discovery recorded \d+ facts/i)).toBeVisible()

    // Facts arrive keyed and sourced. The vendor is the one the fake reports,
    // and the row says it was discovered rather than declared.
    await expect(page.getByText('bmc.firmware')).toBeVisible()
    await expect(page.getByText(/\(discovered\)/).first()).toBeVisible()
  })

  test('registers a machine as do-not-touch and raises it one rung with a reason', async ({ page }) => {
    const name = `e2e-registered-${Date.now().toString(36)}`

    await page.getByRole('button', { name: /register a machine/i }).click()
    const form = page.getByRole('form', { name: /register a machine/i })
    await expect(form.getByText(/classified do-not-touch/i)).toBeVisible()

    await form.getByLabel(/^name$/i).fill(name)
    await form.getByLabel(/management address/i).fill('10.66.0.99')
    await form.getByRole('button', { name: /^register$/i }).click()

    await expect(row(page, name)).toContainText(/do not touch/i)

    await row(page, name).getByRole('button', { name: /^open$/i }).click()
    await page.getByRole('button', { name: /^classify$/i }).click()

    const dialog = page.getByRole('dialog')
    // One rung up from the floor is discovery-only; nothing higher is offered.
    await expect(dialog.getByRole('option', { name: /configuration allowed/i })).toHaveCount(0)
    await dialog.getByLabel(/new classification/i).selectOption('discovery_only')
    await expect(dialog.getByRole('button', { name: /^classify$/i })).toBeDisabled()
    await dialog.getByLabel(/why\?/i).fill('Rack card says it is ours and idle.')
    await dialog.getByRole('button', { name: /^classify$/i }).click()

    await expect(row(page, name)).toContainText(/discovery only/i)
  })

  test('records a GPU in a machine without touching it, and the classification stays where it was', async ({ page }) => {
    const name = `e2e-gpu-host-${Date.now().toString(36)}`

    await page.getByRole('button', { name: /register a machine/i }).click()
    const form = page.getByRole('form', { name: /register a machine/i })
    await form.getByLabel(/^name$/i).fill(name)
    await form.getByRole('button', { name: /^register$/i }).click()
    await row(page, name).getByRole('button', { name: /^open$/i }).click()

    await expect(page.getByText(/no gpu device is recorded/i)).toBeVisible()
    await page.getByRole('button', { name: /record a gpu/i }).click()

    const dialog = page.getByRole('dialog')
    const confirm = dialog.getByRole('button', { name: /record a gpu/i })
    await expect(confirm).toBeDisabled()
    await dialog.getByLabel(/^vendor$/i).fill('NVIDIA')
    await dialog.getByLabel(/^model$/i).fill('L40S')
    await dialog.getByLabel(/vram/i).fill('49152')
    await dialog.getByLabel(/pci address/i).fill('0000:41:00.0')
    await expect(confirm).toBeEnabled()
    await confirm.click()

    const gpus = page.getByRole('list', { name: /^gpu devices$/i })
    await expect(gpus).toContainText(/NVIDIA L40S/)
    await expect(gpus).toContainText(/0000:41:00\.0/)
    await expect(gpus).toContainText(/whole device/i)
    await expect(gpus).toContainText(/^.*available/i)

    // Recording is not touching: the machine is still do-not-touch.
    await expect(row(page, name)).toContainText(/do not touch/i)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the machine screen reads in Arabic and keeps hostnames unmirrored', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/machines')

    await expect(page.getByRole('heading', { name: /الأجهزة/ })).toBeVisible()
    await expect(row(page, fixtures.machineUntouched)).toContainText(/لا تلمس/)
    await expect(row(page, fixtures.machineReachable)).toContainText(/استكشاف فقط/)

    const name = row(page, fixtures.machineReachable).locator('.technical').first()
    await expect(name).toHaveText(fixtures.machineReachable)
  })
})
