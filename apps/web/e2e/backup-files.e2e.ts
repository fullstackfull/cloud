import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Files out of a backup, in a browser.
 *
 * The seeder writes one finished backup on the fake provider, which holds
 * a fixed tree: a nested directory, regular files, a symlink and a device.
 * What the browser proves that the API tests cannot: the listing is
 * navigable, a symlink is visibly a link and cannot be ticked, a download
 * opens a new window rather than printing a link, and a file restore
 * needs the hostname typed and then shows as a row with a state.
 *
 * The needs-review backup is left alone: its files cannot be opened, and
 * the screen says so on the disabled button.
 */

async function openFiles(page: Page): Promise<void> {
  await page.goto('/backups')
  await page.getByRole('combobox').selectOption({ label: fixtures.vpsHostname })

  // The finished backup's Files button is enabled; the needs-review one is not.
  await expect(page.getByRole('button', { name: /^files$/i })).toHaveCount(2)
  await expect(page.locator('button:disabled', { hasText: /^files$/i })).toHaveCount(1)
  await page.locator('button:enabled', { hasText: /^files$/i }).first().click()
  await expect(page.getByTestId('backup-file-path')).toHaveText('/')
}

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('a backup is browsed folder by folder, and a symlink is shown and offered for nothing', async ({ page }) => {
    await openFiles(page)

    const table = page.getByRole('table', { name: /^files$/i })
    await expect(table.getByRole('row').filter({ hasText: 'etc/' })).toBeVisible()

    await table.getByRole('button', { name: 'etc/' }).click()
    await expect(page.getByTestId('backup-file-path')).toHaveText('/etc')

    const link = table.getByRole('row').filter({ hasText: 'localtime' })
    await expect(link.getByText(/^link$/i)).toBeVisible()
    await expect(link.getByRole('checkbox')).toBeDisabled()
    await expect(link.getByRole('button', { name: /download/i })).toHaveCount(0)

    // A regular file can be taken away; a folder can be entered.
    await expect(table.getByRole('row').filter({ hasText: 'hostname' }).getByRole('button', { name: /download/i })).toBeVisible()
    await page.getByRole('button', { name: /up one level/i }).click()
    await expect(page.getByTestId('backup-file-path')).toHaveText('/')
  })

  test('a download opens in a new window and the link is never printed', async ({ page, context }) => {
    await openFiles(page)

    const table = page.getByRole('table', { name: /^files$/i })
    await table.getByRole('button', { name: 'etc/' }).click()

    const popup = context.waitForEvent('page')
    await table.getByRole('row').filter({ hasText: 'hostname' }).getByRole('button', { name: /download/i }).click()
    const opened = await popup

    // The token is in the URL that was opened, and nowhere on the page.
    expect(opened.url()).toMatch(/\/api\/v1\/backups\/downloads\/[0-9a-f]{64}$/)
    await expect(page.getByText(/backups\/downloads\//)).toHaveCount(0)
    await opened.close()
  })

  test('files are put back only after the hostname is typed, and the restore shows as a row', async ({ page }) => {
    await openFiles(page)

    const table = page.getByRole('table', { name: /^files$/i })
    await table.getByRole('button', { name: 'etc/' }).click()
    await table.getByRole('row').filter({ hasText: 'hostname' }).getByRole('checkbox').check()
    await expect(page.getByText(/1 selected/i)).toBeVisible()

    await page.getByRole('button', { name: /restore selected/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/cannot be undone/i)).toBeVisible()
    await expect(dialog.getByText('/etc/hostname')).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /^restore files$/i })
    await expect(confirm).toBeDisabled()
    await dialog.getByRole('textbox').fill(fixtures.vpsHostname.toUpperCase())
    await expect(confirm).toBeDisabled()
    await dialog.getByRole('textbox').fill(fixtures.vpsHostname)
    await confirm.click()
    await expect(page.getByRole('dialog')).toBeHidden()

    const row = page.getByTestId('file-restore-row').first()
    await expect(row).toContainText('/etc/hostname')
    await expect(row.getByText(/running|requested/i)).toBeVisible()

    // A second restore into the same server is refused while this one runs.
    await table.getByRole('row').filter({ hasText: 'nginx' }).getByRole('checkbox').check()
    await page.getByRole('button', { name: /restore selected/i }).click()
    await page.getByRole('dialog').getByRole('textbox').fill(fixtures.vpsHostname)
    await page.getByRole('dialog').getByRole('button', { name: /^restore files$/i }).click()
    await expect(page.getByRole('dialog').getByRole('alert')).toBeVisible()
    await page.keyboard.press('Escape')
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the file browser reads in Arabic and keeps paths left-to-right', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await page.goto('/backups')
    await page.getByRole('combobox').selectOption({ label: fixtures.vpsHostname })
    await page.locator('button:enabled', { hasText: 'الملفات' }).first().click()

    const path = page.getByTestId('backup-file-path')
    await expect(path).toHaveText('/')
    expect(await path.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    await expect(page.getByText(/الروابط الرمزية تُعرض ولا تُتبع/)).toBeVisible()
    await page.getByRole('table').getByRole('button', { name: 'etc/' }).click()
    await expect(page.getByText('رابط').first()).toBeVisible()
  })
})
