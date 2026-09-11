import { expect, test, type Page } from '@playwright/test'

import { expectAccessible } from '../support/axe'
import { documentLanguage, fixtures, signIn, users } from '../support/helpers'
import { expectArabicProse } from '../support/language'

/*
 * W5.6 item 13. The accessibility work, checked again in Arabic.
 *
 * Not a repeat of the English pass. Several of the mechanisms Wave 5 built
 * behave differently in a right-to-left page, and each of these is a defect
 * that cannot happen in English:
 *
 *  - An accessible name assembled from two strings — a verb and a Latin
 *    identifier — is announced in the order the name was built, not the order
 *    it is drawn. "عرض الفاتورة INV-E2E-0001" read as its parts reversed is a
 *    different sentence.
 *  - A technical value that inherits the page direction is a *different value*:
 *    198.51.100.25 mirrored is another address, and a screen reader reading
 *    the wrong direction reads the wrong number.
 *  - A control positioned with `left` rather than `start` appears in the
 *    opposite corner, which for the skip link means off the page.
 *
 * The automated pass runs here too, because several axe rules are
 * direction-sensitive in practice and because a label that exists in English
 * and is missing from the Arabic catalogue is a control with no name.
 */

const WELCOME = /مرحب|أهل/

async function signInArabic(page: Page): Promise<void> {
  await signIn(page, users.customer, { headingPattern: WELCOME })
  expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
}

test.describe('an Arabic customer, by keyboard and by screen reader', () => {
  test.beforeEach(async ({ page }) => {
    await signInArabic(page)
  })

  test('reaches the content past the navigation, with the skip link in Arabic', async ({ page }) => {
    /*
     * The skip link is positioned with `start-4`, so in Arabic it belongs in
     * the other corner. Positioned with `left-4` it would still be focusable
     * and still work — and would appear off the side of the page, which is the
     * failure mode this catches: it is asserted visible once focused, not
     * merely present.
     */
    await page.reload()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await page.keyboard.press('Tab')

    const skip = page.getByRole('link', { name: /تخطٍ إلى المحتوى/ })
    await expect(skip).toBeFocused()
    await expect(skip).toBeInViewport()

    await page.keyboard.press('Enter')

    expect(await page.evaluate(() => document.activeElement?.id ?? '')).toBe('main-content')
  })

  test('has a named navigation landmark and Arabic destinations', async ({ page }) => {
    const nav = page.getByRole('navigation', { name: 'التنقّل الرئيسي' })

    await expect(nav).toBeVisible()

    // A destination whose name is still English is a translation that did not
    // happen; one whose name is a key is worse.
    await expect(nav.getByRole('link', { name: 'الفواتير' })).toBeVisible()
    await expect(nav.getByRole('link', { name: 'الأمان' })).toBeVisible()
  })

  test('names a row action with its subject, in Arabic and in one piece', async ({ page }) => {
    /*
     * The name is built from an Arabic verb and a Latin invoice number. What
     * matters is that both are in the accessible name and that the number
     * survives intact — a name assembled by concatenating around the number
     * comes out as fragments.
     */
    await page.goto('/invoices')

    const link = page.getByRole('link', { name: `عرض الفاتورة ${fixtures.openInvoice}` })

    await expect(link).toBeVisible()

    // And nothing is left named by the bare verb, which would announce as a
    // page of identical links.
    await expect(page.getByRole('link', { name: /^عرض$/ })).toHaveCount(0)
  })

  test('keeps a time zone readable as a place rather than mirrored', async ({ page }) => {
    /*
     * "Asia/Kuwait" is an identifier with a slash in it, and a slash inside a
     * right-to-left line moves: the component declares `dir="ltr"` on the
     * control so the name stays the name. The label around it is Arabic.
     */
    await page.goto('/profile')

    const zone = page.getByLabel('المنطقة الزمنية')

    await expect(zone).toBeVisible()
    expect(await zone.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')
  })

  test('reads the role matrix as a table of words, not of glyphs', async ({ page }) => {
    /*
     * The matrix is the densest thing in the portal and the easiest to render
     * as a grid of ticks. A tick is not a word: every cell carries the answer
     * in text as well, and in Arabic that text has to be Arabic.
     */
    await page.goto('/settings/team')

    const matrix = page.getByRole('table').filter({ hasText: /الدور|المالك/ }).first()

    await expect(matrix).toBeVisible()

    const headers = matrix.getByRole('columnheader')
    expect(await headers.count(), 'the matrix names its columns').toBeGreaterThan(1)

    // Every cell says something; a cell whose only content is an aria-hidden
    // glyph is announced as an empty cell.
    const cells = await matrix.getByRole('cell').all()

    expect(cells.length).toBeGreaterThan(0)

    for (const cell of cells) {
      expect((await cell.innerText()).trim(), 'a cell with nothing to announce').not.toBe('')
    }
  })

  test('asks before a destructive action, in Arabic, and takes the cancellation', async ({
    page,
  }) => {
    /*
     * The dialogue mechanics in Arabic: focus moves inside, Escape dismisses,
     * and cancelling leaves the machine alone. The business rule is Wave 0's
     * and is not being changed to suit a test — only checked in the other
     * direction.
     */
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    const force = page.getByRole('button', { name: 'إيقاف قسري', exact: true })
    await force.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await expectArabicProse(dialog)

    expect(
      await page.evaluate(() => {
        const open = document.querySelector('dialog[open]')

        return open !== null && open.contains(document.activeElement)
      }),
      'focus is inside the dialogue',
    ).toBe(true)

    await page.keyboard.press('Escape')

    await expect(dialog).toBeHidden()
    await expect(force, 'focus is back on the control that opened it').toBeFocused()
  })
})

test.describe('the automated pass, in Arabic', () => {
  test.beforeEach(async ({ page }) => {
    await signInArabic(page)
  })

  test('a resource page with its sections and controls', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'vps detail (Arabic)')
  })

  test('the team screen and its matrix', async ({ page }) => {
    await page.goto('/settings/team')
    await expect(page.getByRole('table').first()).toBeVisible()

    await expectAccessible(page, 'team (Arabic)')
  })

  test('the security page and its tables', async ({ page }) => {
    await page.goto('/security')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await expectAccessible(page, 'security (Arabic)')
  })
})
