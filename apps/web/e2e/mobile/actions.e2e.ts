import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * Representative controls, pressed from a phone. Not every Wave 0 mutation
 * again — the desktop suite owns those — but one per product family, each
 * reached through the drawer, so the claim "the portal works on a phone" is a
 * claim about operating it and not only about looking at it.
 */

test.describe('a customer operating the portal from a phone', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('shuts a machine down and starts it again', async ({ page }) => {
    await (await openMenu(page)).getByRole('link', { name: /^cloud vps$/i }).click()
    const row = page.getByRole('row').filter({ hasText: fixtures.operableHostname })
    await expect(row).toBeVisible()

    const accepted = (action: string) =>
      page.waitForResponse((response) => response.url().endsWith('/power') && response.request().postData()?.includes(action) === true)

    const shutdown = accepted('shutdown')
    await row.getByRole('button', { name: /^shut down$/i }).click()
    expect((await shutdown).status()).toBe(202)
    await expect(row.getByText(/^stopped$/i)).toBeVisible()

    const start = accepted('start')
    await row.getByRole('button', { name: /^start$/i }).click()
    expect((await start).status()).toBe(202)
    await expect(row.getByText(/^running$/i)).toBeVisible()
  })

  test('claims a DNS zone, publishes a record, removes it after the dialog, and gives the zone up', async ({ page }) => {
    const domain = 'phone-claimed.test'
    await (await openMenu(page)).getByRole('link', { name: /^dns$/i }).click()

    await page.getByLabel(/^domain$/i).first().fill(domain)
    await page.getByRole('button', { name: /add domain/i }).click()
    await expect(page.getByRole('heading', { name: domain })).toBeVisible()

    await page.getByLabel(/name \(blank for/i).fill('m')
    await page.getByLabel(/^value$/i).fill('203.0.113.77')
    await page.getByRole('button', { name: /add record/i }).click()
    const row = page.getByRole('row').filter({ hasText: 'm.' + domain })
    await expect(row).toHaveCount(1)

    await row.getByRole('button', { name: /^remove$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^remove record$/i }).click()
    await expect(page.getByRole('row').filter({ hasText: 'm.' + domain })).toHaveCount(0)

    await page.getByRole('button', { name: /give up domain/i }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(domain)
    await dialog.getByRole('button', { name: /give up domain/i }).click()
    await expect(page.getByRole('heading', { name: domain })).toHaveCount(0)
  })

  test('opens the credit dialogue on an invoice the credit does not cover, reads the shortfall, and closes it', async ({
    page,
  }) => {
    await (await openMenu(page)).getByRole('link', { name: /^invoices$/i }).click()
    const row = page.getByRole('row').filter({ hasText: fixtures.largeInvoice })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: /use credit/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/does not cover the whole invoice/i)).toBeVisible()
    await expect(dialog.getByText(/credit available/i)).toBeVisible()
    await expect(dialog.getByText(/still to pay/i)).toBeVisible()
    await dialog.getByRole('button', { name: /cancel|close/i }).first().click()
    await expect(dialog).toBeHidden()
  })

  test('creates an API token and revokes it after the dialog', async ({ page }) => {
    await (await openMenu(page)).getByRole('link', { name: /^api keys$/i }).click()

    await page.getByLabel(/^name$/i).fill('phone-token')
    await page.getByLabel(/current password/i).fill(users.customer.password)
    await page.getByRole('button', { name: /create token/i }).click()
    await expect(page.getByText(/only time it is shown/i)).toBeVisible()

    const row = page.getByRole('row').filter({ hasText: 'phone-token' })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: /^revoke$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^revoke/i }).click()
    await expect(row.getByText(/^revoked$/i)).toBeVisible()
  })

  test('replies on a support ticket', async ({ page }) => {
    await (await openMenu(page)).getByRole('link', { name: /^support$/i }).click()
    await page.getByRole('button', { name: fixtures.ticketSubject }).click()

    await page.getByLabel(/your reply/i).fill('Sent from my phone: still refused.')
    await page.getByRole('button', { name: /^reply$/i }).click()
    await expect(page.getByText('Sent from my phone: still refused.')).toBeVisible()
  })
})
