import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The execution chain from the operator's chair: assign a profile, compute
 * the plan, approve it as somebody else, run it, and watch it complete
 * against the fake controller. Then the screen that lists runs.
 *
 * Proven in PHPUnit: every refusal, the fingerprint rule, the replay guard,
 * the timeout rule. What the browser adds is that the four decisions are
 * four separate controls, each offered only when the API would accept it,
 * and that the approval shows the fingerprint it is for.
 *
 * Fixture: e2e-node-03 is configuration-allowed with a fake management
 * address; the operator login is a super-admin, which the four-eyes rule
 * treats as two people only because two accounts are used — so the
 * approval is given by the NOC login, which holds deployment.approve
 * through the super-admin gate? No: noc holds only the view permission.
 * The approval here is given by the second super-admin fixture.
 */

test.describe('an operator walking the execution chain', () => {
  test('assigns, plans, is refused as approver of their own plan, and runs once approved', async ({ page, browser }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/plans')
    await expect(page.getByRole('heading', { name: /^plans$/i })).toBeVisible()

    await page.getByLabel(/^machine$/i).selectOption({ label: fixtures.machineConfigurable })

    // Desired state: a profile with one declared override.
    const form = page.getByRole('form', { name: /assign profile/i })
    await form.getByLabel(/^profile$/i).selectOption('redis')
    await form.getByLabel('redis.maxmemory').fill('1gb')
    await form.getByRole('button', { name: /assign profile/i }).click()
    await expect(page.getByText(/^redis$/).first()).toBeVisible()

    // Plan: three installs, moderate risk, a fingerprint, no approval.
    await page.getByRole('button', { name: /compute plan/i }).click()
    const changes = page.getByRole('list', { name: /3 changes/i })
    await expect(changes.getByText('redis', { exact: true })).toBeVisible()
    await expect(page.getByText(/risk: moderate/i)).toBeVisible()
    await expect(page.getByText(/not approved/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /run now/i })).toBeDisabled()

    // The planner may not approve their own plan; the platform says so.
    await page.getByRole('button', { name: /^approve$/i }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel(/why\?/i).fill('Reviewed the three changes.')
    await dialog.getByRole('button', { name: /^approve$/i }).click()
    await expect(dialog.getByText(/two people, or none/i)).toBeVisible()
    await dialog.getByRole('button', { name: /cancel/i }).click()

    // Somebody else approves.
    const other = await browser.newContext()
    const otherPage = await other.newPage()
    await signIn(otherPage, users.secondOperator)
    await otherPage.goto('/admin/control-center/plans')
    await otherPage.getByLabel(/^machine$/i).selectOption({ label: fixtures.machineConfigurable })
    await otherPage.getByRole('button', { name: /^approve$/i }).click()
    const otherDialog = otherPage.getByRole('dialog')
    await otherDialog.getByLabel(/why\?/i).fill('Reviewed the three changes; lab machine.')
    await otherDialog.getByRole('button', { name: /^approve$/i }).click()
    await expect(otherPage.getByText(/approved .* for this exact fingerprint/i)).toBeVisible()
    await other.close()

    // Back with the planner: the approval is visible and the run is offered.
    await page.reload()
    await page.getByLabel(/^machine$/i).selectOption({ label: fixtures.machineConfigurable })
    await expect(page.getByText(/approved .* for this exact fingerprint/i)).toBeVisible()
    await page.getByRole('button', { name: /run now/i }).click()

    // The queue is synchronous in the browser suite, so the run is done.
    await expect(page.getByText(/^completed$/i).first()).toBeVisible()

    // And the deployments screen lists it with its steps.
    await page.goto('/admin/control-center/deployments')
    const row = page.getByRole('row').filter({ has: page.getByText(fixtures.machineConfigurable, { exact: true }) }).first()
    await expect(row).toContainText(/apply/i)
    await expect(row).toContainText(/completed/i)
    await row.getByRole('button', { name: /^open$/i }).click()
    await expect(page.getByText(/✓ role:redis/)).toBeVisible()
    await expect(page.getByText(/✓ verify:redis/)).toBeVisible()
    await expect(page.getByRole('button', { name: /^resolve$/i })).toHaveCount(0)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the plans and deployments screens read in Arabic', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/plans')
    await expect(page.getByRole('heading', { name: /الخطط/ })).toBeVisible()

    await page.goto('/admin/control-center/deployments')
    await expect(page.getByRole('heading', { name: /عمليات النشر/ })).toBeVisible()
  })
})
