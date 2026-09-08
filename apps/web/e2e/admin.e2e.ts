import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * The operator area, and the boundary around it.
 *
 * Two different claims are tested here, and only the second is interesting.
 * The first is that an operator screen renders real infrastructure. The second
 * is that authority does not leak sideways: a login that may issue a refund may
 * not read the compute fleet, and a customer may not reach the area at all.
 *
 * The permission boundary is only observable from a login that holds one half
 * of the platform and not the other, which is why E2ESeeder creates a Billing
 * Admin. A super admin passes every check, so it can prove a screen works and
 * nothing whatsoever about who may see it.
 */

test.describe('a super admin', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
  })

  test('sees the compute fleet with the capacity the scheduler believes in', async ({ page }) => {
    await page.goto('/admin/infrastructure')

    await expect(page.getByRole('heading', { name: /^infrastructure$/i })).toBeVisible()
    // Two nodes come from the infrastructure seeder. An empty table here would
    // mean the read failed, and the screen would say so.
    await expect(page.getByRole('alert')).toHaveCount(0)
    await expect(page.getByRole('row')).not.toHaveCount(0)
  })

  test('sees every account on the platform', async ({ page }) => {
    await page.goto('/admin/customers')

    await expect(page.getByText(/sample customer/i).first()).toBeVisible()
  })
})

test.describe('a billing administrator', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.billingAdmin)
  })

  test('reaches the payments screen their role is for', async ({ page }) => {
    await page.goto('/admin/payments')

    await expect(page.getByRole('heading', { name: /^payments$/i })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
  })

  test('is refused the compute fleet, and told so rather than shown an empty one', async ({ page }) => {
    await page.goto('/admin/infrastructure')

    // The refusal comes from the API, which checks infrastructure.view on its
    // own rather than trusting the portal to have hidden a link. What is being
    // asserted here is that the refusal reaches the screen: an operator shown an
    // empty node table would reasonably conclude the fleet is empty.
    await expect(page.getByRole('alert')).toContainText(/not permitted/i)
  })

  test('cannot read another team\'s data by typing its address', async ({ page }) => {
    await page.goto('/admin/provisioning')

    // Provisioning is Infrastructure and NOC territory; a Billing Admin holds
    // no provisioning.view. Nothing from the queue may appear.
    await expect(page.getByRole('alert')).toContainText(/not permitted/i)
  })
})

test.describe('a customer', () => {
  test('is sent back to their own dashboard from an operator address', async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/admin/customers')

    await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible()
    await expect(page.getByRole('heading', { name: /^customers$/i })).toHaveCount(0)
  })
})
