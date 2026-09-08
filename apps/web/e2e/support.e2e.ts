import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Support, from both sides of the same ticket.
 *
 * The property only a browser can prove: one row in the database renders
 * differently for the customer and for the operator, and the difference is an
 * internal note. A backend test proves the query excludes it; this proves the
 * page a person actually reads does not contain it.
 */

test.describe('the customer', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('sees their ticket and the replies, and never the internal note', async ({ page }) => {
    await page.goto('/support')

    await expect(page.getByText(fixtures.ticketReference)).toBeVisible()

    await page.getByRole('button', { name: fixtures.ticketSubject }).click()

    await expect(page.getByText(/refusing connections since about nine/i)).toBeVisible()
    await expect(page.getByText(/rebooted for firmware. Your machine is back up/i)).toBeVisible()

    // Not merely hidden from the rendered list — absent from the bytes.
    const html = await page.content()
    expect(html).not.toContain(fixtures.ticketInternalNote)
  })

  test('can open a ticket and is offered no priority above high', async ({ page }) => {
    await page.goto('/support')

    // Urgent is what pages somebody out of hours; it is an operator's
    // judgement and the customer's form must not offer it.
    const priority = page.getByLabel(/priority/i)
    await expect(priority.getByRole('option', { name: /urgent/i })).toHaveCount(0)
    await expect(priority.getByRole('option', { name: /high/i })).toHaveCount(1)

    await page.getByLabel(/^subject$/i).fill('My backups have not run')
    await page.getByLabel(/what is happening/i).fill('The last one I can see is from Tuesday.')
    await page.getByRole('button', { name: /send request/i }).click()

    await expect(page.getByText('My backups have not run').first()).toBeVisible()
  })

  test('can reply on a ticket, which hands it back to the support team', async ({ page }) => {
    await page.goto('/support')
    await page.getByRole('button', { name: fixtures.ticketSubject }).click()

    await page.getByLabel(/your reply/i).fill('Still refused at 09:40.')
    await page.getByRole('button', { name: /^reply$/i }).click()

    await expect(page.getByText('Still refused at 09:40.')).toBeVisible()
    // The row now says it is with us rather than with them.
    await expect(page.getByText(/waiting for us/i).first()).toBeVisible()
  })
})

test.describe('the operator', () => {
  test('sees the internal note, and is warned before writing another', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/support')

    await page.getByRole('button', { name: fixtures.ticketReference }).click()

    // The same row the customer read, with the half they cannot see.
    await expect(page.getByText(fixtures.ticketInternalNote)).toBeVisible()

    await page.getByLabel(/internal note — the customer will not see this/i).check()

    // The one control on this screen that must never be pressed by accident.
    await expect(page.getByText(/will not be sent to the customer/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /add note/i })).toBeVisible()
  })

  test('can raise a ticket to urgent', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/support')

    await page.getByRole('button', { name: fixtures.ticketReference }).click()

    await page.getByLabel(/^priority$/i).last().selectOption('urgent')

    await expect(page.getByText(/urgent/i).first()).toBeVisible()
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the support screen reads in Arabic', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/support')

    await expect(page.getByRole('heading', { name: 'الدعم' })).toBeVisible()
    await expect(page.getByText('طلباتك')).toBeVisible()

    await page.getByRole('button', { name: fixtures.ticketSubject }).click()
    await expect(page.getByText('ردّك')).toBeVisible()

    // The reference is technical and must not be mirrored.
    const reference = page.getByText(fixtures.ticketReference).first()
    expect(await reference.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')
  })
})
