import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * Ending a subscription, in a browser.
 *
 * This is the act that decides when somebody's data is destroyed, so what the
 * suite checks is the sentence rather than the mechanism: both dates in front
 * of the customer before they confirm, the two forms of the decision clearly
 * different from each other, and the dangerous one behind a typed reference.
 *
 * The active subscription is left as it was found — the specs here stop at the
 * dialogue, except the one that has to go through with it, which the seeder
 * rebuilds on the next run.
 */

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/subscriptions')
  })

  test('the cancellation dialogue says when the service stops and when the data goes', async ({
    page,
  }) => {
    await page.getByRole('button', { name: /^cancel$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    await expect(dialog.getByText(/will not renew/i)).toBeVisible()

    // The second date, which is the one customers ring support about, and the
    // number in it comes from the server rather than the portal.
    await expect(dialog.getByText(/kept for \d+ days/i)).toBeVisible()

    /*
     * And the confirm button never reads "Cancel". The dismiss button says
     * that, and in a dialogue it means "do not do this" — two buttons reading
     * Cancel, one of which ends the customer's service, is the kind of thing
     * somebody clicks once and remembers for years.
     */
    await expect(dialog.getByRole('button', { name: /^end subscription$/i })).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })

  test('ending one today is a different sentence, behind a typed reference', async ({ page }) => {
    await page.getByRole('button', { name: /^cancel$/i }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('checkbox').check()

    // What is lost is different, so the sentence is different.
    await expect(dialog.getByText(/is not refunded/i)).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /^end it now$/i })
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill('not-this-subscription')
    await expect(confirm).toBeDisabled()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })

  test('a cancellation that is scheduled shows on the row and is not offered twice', async ({
    page,
  }) => {
    // The seeded pair: one renewing, one already ending. Offering to cancel
    // the second would be a button that does nothing.
    await expect(page.getByRole('button', { name: /^cancel$/i })).toHaveCount(1)
    await expect(page.getByText(/^Ends /i)).toBeVisible()
  })

  test('the services list shows the date a stopped service loses its data', async ({ page }) => {
    await page.goto('/services')

    // The column exists whether or not anything is ending: a customer with six
    // services and one ending has to be able to see which from here.
    await expect(page.getByRole('columnheader', { name: /data kept until/i })).toBeVisible()
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the cancellation dialogue reads in Arabic and keeps the reference unmirrored', async ({
    page,
  }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/subscriptions')

    await page.getByRole('button', { name: 'إلغاء' }).first().click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('إنهاء هذا الاشتراك؟')).toBeVisible()

    await dialog.getByRole('checkbox').check()

    const reference = dialog.locator('p.technical')
    expect(await reference.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})
