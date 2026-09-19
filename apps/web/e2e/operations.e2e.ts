import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The screens a person reaches for when something has gone wrong.
 *
 * Every fixture behind this file is a failure: a rebuild whose outcome nobody
 * knows, a physical rebuild the installer refused, a machine the hypervisor
 * disagrees with the platform about, and two services a customer cannot use.
 * That is deliberate — an operator screen proved against healthy data proves
 * that a table renders, and every control worth having only appears next to a
 * row that needs it.
 */

test.describe('the rebuilds queue', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
  })

  test('shows both kinds of rebuild and says whose data is gone', async ({ page }) => {
    await page.goto('/admin/operations')

    await expect(page.getByRole('heading', { name: /^rebuilds$/i })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    // A virtual machine's rebuild and a physical one, in one queue.
    await expect(page.getByText(/vps rebuild/i).first()).toBeVisible()
    await expect(page.getByText(/dedicated rebuild/i).first()).toBeVisible()

    // The fact a customer rings about, on the screen rather than in the
    // database: both seeded operations began their destructive phase.
    await expect(page.getByText(/^destroyed$/i).first()).toBeVisible()
  })

  test('narrows to the ones waiting for a person', async ({ page }) => {
    await page.goto('/admin/operations')

    await page.getByRole('button', { name: /only the ones waiting/i }).click()

    // Both seeded rebuilds are waiting, and the verdict controls are what a
    // waiting row is for.
    await expect(page.getByRole('button', { name: /confirm it finished/i }).first()).toBeVisible()
  })

  test('a verdict cannot be recorded without saying what was seen', async ({ page }) => {
    await page.goto('/admin/operations')

    await page.getByRole('button', { name: /confirm it finished/i }).first().click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The operator is asserting something the platform could not check for
    // itself. An assertion with no stated basis is not reviewable, so the
    // confirm button stays shut until there is one.
    const confirm = dialog.getByRole('button', { name: /confirm it finished/i })
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill('ok')
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill('VM 910 on e2e-node is running and answering on port 22.')
    await expect(confirm).toBeEnabled()
  })

  test('escape closes the verdict without settling anything', async ({ page }) => {
    await page.goto('/admin/operations')

    await page.getByRole('button', { name: /record it as failed/i }).first().click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await page.keyboard.press('Escape')

    await expect(page.getByRole('dialog')).toBeHidden()
    // Still waiting: the row's controls are still offered.
    await expect(page.getByRole('button', { name: /confirm it finished/i }).first()).toBeVisible()
  })
})

test.describe('the drift queue', () => {
  test('shows the disagreement and both sides of it', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/drift')

    await expect(page.getByRole('heading', { name: /^drift$/i })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    // The seeded finding is a suspension the hypervisor is not enforcing —
    // a customer who owes money and whose machine is running anyway.
    await expect(page.getByText(/suspension not enforced/i).first()).toBeVisible()
    await expect(page.getByText(/^critical$/i).first()).toBeVisible()
    await expect(page.getByText(/lynomia-suspended/).first()).toBeVisible()
  })

  test('an operator who may look but not decide is refused by the API', async ({ page }) => {
    /*
     * NOC holds drift.view and not drift.resolve, deliberately: a shift can be
     * given the screen without being given the authority to declare a
     * customer's missing machine a non-issue. The portal shows the control and
     * the API refuses it — which is the right way round, because the check
     * that matters is the one the browser cannot skip.
     */
    await signIn(page, users.noc)
    await page.goto('/admin/drift')

    await expect(page.getByText(/suspension not enforced/i).first()).toBeVisible()

    await page.getByRole('button', { name: /^seen$/i }).first().click()

    await expect(page.getByText(/suspension not enforced/i).first()).toBeVisible()
    // Still open: the verdict did not take.
    await expect(page.getByRole('button', { name: /^seen$/i }).first()).toBeVisible()
  })
})

test.describe('the provisioning queue', () => {
  test('offers a retry on work that stopped, and asks what changed', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/provisioning')

    await expect(page.getByRole('heading', { name: /provisioning queue/i })).toBeVisible()

    // The seeded rebuild jobs are both in review, which is what the banner is
    // for: a timed-out job is never retried automatically.
    await expect(page.getByText(/need a human|needs a human/i)).toBeVisible()

    await page.getByRole('button', { name: /run it again/i }).first().click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /run it again/i })
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill('pve-kw-04 was added this morning and has free cores.')
    await expect(confirm).toBeEnabled()
  })

  test('a rebuild that already destroyed a disk is refused, with the reason', async ({ page }) => {
    /*
     * The refusal that matters most on this screen. Running a rebuild again
     * lands a second installation on top of whatever the first one wrote, and
     * no permission makes that a decision a button should be able to take —
     * so the API refuses, and the operator is told why rather than being shown
     * a generic failure.
     */
    await signIn(page, users.operator)
    await page.goto('/admin/provisioning')

    await page.getByRole('button', { name: /run it again/i }).first().click()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill('The customer asked us to try again.')
    await dialog.getByRole('button', { name: /run it again/i }).click()

    await expect(dialog.getByRole('alert')).toContainText(/destroyed data|already/i)
  })
})

test.describe('a customer whose service is not active', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('sees why the buttons are dead rather than a broken screen', async ({ page }) => {
    await page.goto('/vps')

    const suspended = page.getByRole('row').filter({ hasText: fixtures.suspendedHostname })

    await expect(suspended.getByText(/^suspended$/i)).toBeVisible()
    // The one control the index keeps is shut, on the same fact the API
    // refuses on.
    await expect(suspended.getByRole('button', { name: /^reboot$/i })).toBeDisabled()

    // And on the machine's own page, where the rest of them live.
    await page.getByRole('link', { name: fixtures.suspendedHostname }).click()

    for (const name of [/^start$/i, /^shut down$/i, /^force off$/i, /^reboot$/i]) {
      await expect(page.getByRole('button', { name })).toBeDisabled()
    }

    await page
      .getByRole('navigation', { name: /sections/i })
      .getByRole('link', { name: /^danger zone$/i })
      .click()

    await expect(page.getByRole('button', { name: /^reinstall$/i })).toBeDisabled()
  })

  test('a service coming back says so instead of pretending to be active', async ({ page }) => {
    /*
     * The asymmetric half of suspension: the customer has paid, the provider
     * would not confirm the machine is unlocked, and the platform says so
     * rather than marking the service active because the money arrived.
     */
    await page.goto('/vps')

    const reactivating = page.getByRole('row').filter({ hasText: fixtures.reactivatingHostname })

    await expect(reactivating.getByText(/coming back/i)).toBeVisible()
    await expect(reactivating.getByRole('button', { name: /^reboot$/i })).toBeDisabled()
  })
})
