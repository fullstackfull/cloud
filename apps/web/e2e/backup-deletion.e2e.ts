import { expect, test, type Locator, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Deleting a backup, in a browser.
 *
 * The reason this needs one: the guard is a typed reference, and a typed
 * reference is only a guard if the button really stays disabled until the
 * characters match. jsdom can assert the attribute; only a browser can show
 * that a customer cannot get past it — and the same file then follows the
 * whole way round, through the grace period and back, because a deletion that
 * cannot be called off is a different product from the one that was designed.
 *
 * Every spec here leaves the seeded backups as it found them.
 */

async function chooseMachine(page: Page): Promise<void> {
  await page.getByRole('combobox').selectOption({ label: fixtures.vpsHostname })
}

function row(page: Page, state: RegExp): Locator {
  return page.getByRole('row').filter({ hasText: state })
}

/** The archive's own reference, as the dialogue shows it for copying. */
async function reference(dialog: Locator): Promise<string> {
  return (await dialog.locator('p.technical').innerText()).trim()
}

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
    await page.goto('/backups')
    await chooseMachine(page)
  })

  test('a deletion cannot be confirmed until the backup reference is typed back', async ({
    page,
  }) => {
    await row(page, /succeeded/i).getByRole('button', { name: /^delete$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // What is about to be lost, said plainly, and the fact that the server
    // itself is untouched — the confusion that would otherwise send somebody
    // to support after deleting a backup expecting a machine to go with it.
    await expect(dialog.getByText(/cannot be undone/i)).toBeVisible()
    await expect(dialog.getByText(/does not affect the server itself/i)).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /^delete$/i })
    await expect(confirm).toBeDisabled()

    const archive = await reference(dialog)

    // The reference of a different archive: two tabs open is how the wrong
    // backup gets deleted, and it is the case this exists to refuse.
    await dialog.getByRole('textbox').fill('01JNOTTHISBACKUPATALLXXXXXX')
    await expect(confirm).toBeDisabled()

    // Nearly right is still wrong. The server compares it the same way.
    await dialog.getByRole('textbox').fill(archive.slice(0, -1))
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill(archive)
    await expect(confirm).toBeEnabled()

    // Left unconfirmed on purpose: this spec is about the guard, and the seeded
    // backup has to still be there for the next one.
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
    await expect(row(page, /succeeded/i)).toBeVisible()
  })

  test('a deletion asked for by mistake can be called off before the sweep acts', async ({
    page,
  }) => {
    const target = row(page, /succeeded/i)
    await target.getByRole('button', { name: /^delete$/i }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(await reference(dialog))
    await dialog.getByRole('button', { name: /^delete$/i }).click()

    await expect(page.getByRole('dialog')).toBeHidden()

    /*
     * Asked for, not gone. The row still says what it is, because nothing has
     * touched the datastore yet — showing "deleted" here would be the platform
     * claiming an outcome it has not got.
     */
    const pending = row(page, /deletion requested/i)
    await expect(pending).toBeVisible()
    await expect(pending.getByText(/deletion asked for/i)).toBeVisible()

    // And the way back, which is the whole reason for the grace period.
    await expect(pending.getByRole('button', { name: /^delete$/i })).toHaveCount(0)
    await pending.getByRole('button', { name: /^keep$/i }).click()

    await expect(row(page, /succeeded/i)).toBeVisible()
    await expect(page.getByText(/deletion requested/i)).toHaveCount(0)
  })

  test('a backup the platform cannot account for is refused, and says why', async ({ page }) => {
    /*
     * The needs-review backup stopped being trackable: the platform does not
     * know whether the archive exists, so it will not tell a provider to
     * remove it and will not pretend to the customer that it did. The refusal
     * has to arrive as words in the dialogue — a delete that quietly does
     * nothing is worse than one that says no.
     */
    await row(page, /needs.review/i).getByRole('button', { name: /^delete$/i }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(await reference(dialog))
    await dialog.getByRole('button', { name: /^delete$/i }).click()

    await expect(dialog.getByRole('alert')).toBeVisible()
    await expect(dialog).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(row(page, /needs.review/i)).toBeVisible()
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the deletion dialogue reads in Arabic, and the reference does not mirror', async ({
    page,
  }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/backups')
    await chooseMachine(page)

    await page.getByRole('button', { name: 'حذف' }).first().click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('حذف هذه النسخة الاحتياطية؟')).toBeVisible()
    await expect(dialog.getByText(/لا يمكن التراجع عنه/)).toBeVisible()

    // The archive reference is technical. Mirrored, it is a different string
    // to anybody reading it back to support.
    const archive = dialog.locator('p.technical')
    expect(await archive.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    // And the guard is the same guard: no Arabic wording gets past it.
    const confirm = dialog.getByRole('button', { name: 'حذف' })
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill((await archive.innerText()).trim())
    await expect(confirm).toBeEnabled()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})
