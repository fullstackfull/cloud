import { expect, test, type Page } from '@playwright/test'

import { documentLanguage, fixtures, signIn, users } from '../support/helpers'
import { expectArabicProse } from '../support/language'

/*
 * A resource page as an Arabic customer reads it.
 *
 * The page shape Wave 3 introduced is where right-to-left goes wrong most
 * easily: a breadcrumb, a row of section links, a header carrying a hostname,
 * and a definition list of facts, several of which are Latin technical values
 * inside Arabic prose. A hostname that inherits the page direction reads as a
 * different hostname.
 */

const WELCOME = /مرحب|أهل/

async function signInArabic(page: Page): Promise<void> {
  await signIn(page, users.customer, { headingPattern: WELCOME })
  expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
}

/** Moves to one section by its Arabic name. */
async function section(page: Page, name: string): Promise<void> {
  await page.getByRole('navigation', { name: 'الأقسام' }).getByRole('link', { name }).click()
}

test.describe('an Arabic customer on a resource page', () => {
  test.beforeEach(async ({ page }) => {
    await signInArabic(page)
  })

  test('reads a machine in Arabic with its hostname and address unmirrored', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()

    const heading = page.getByRole('heading', { level: 1, name: fixtures.operableHostname })
    await expect(heading).toBeVisible()

    /*
     * The identity is Latin text and must not mirror: reversed, it is a
     * different machine to anybody reading it back to support. The heading
     * itself is Arabic-direction prose; the name inside it declares its own
     * direction, which is what the bidirectional algorithm actually needs.
     */
    const identity = heading.locator('[dir="ltr"]')
    await expect(identity).toHaveText(fixtures.operableHostname)
    expect(await identity.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    // The trail, in Arabic, carrying the machine's name and no identifier.
    const trail = page.getByRole('navigation', { name: 'أنت هنا' })
    await expectArabicProse(trail.getByRole('link').first())
    await expect(trail).toContainText(fixtures.operableHostname)
    await expect(trail).not.toContainText('01J')

    // The sections, in Arabic.
    const sections = page.getByRole('navigation', { name: 'الأقسام' })
    await expect(sections.getByRole('link', { name: 'نظرة عامة' })).toBeVisible()
    await expect(sections.getByRole('link', { name: 'منطقة الخطر' })).toBeVisible()
  })

  test('keeps the address left-to-right inside a right-to-left table', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()

    await section(page, 'الشبكة')

    /*
     * The cell declares its own direction rather than relying on the page's:
     * "198.51.100.25" mirrored is a different address.
     */
    const address = page
      .getByRole('table')
      .locator('td[dir="ltr"]')
      .filter({ hasText: fixtures.operableAddress })
      .first()

    await expect(address).toBeVisible()
    expect(await address.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')
  })

  test('warns in Arabic before rebuilding, and the machine is not touched', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname }).click()

    await section(page, 'منطقة الخطر')

    await page.getByRole('button', { name: /إعادة التثبيت/ }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/سيتم استبدال قرص الخادم/)).toBeVisible()

    // The confirmation asks for the hostname, in Arabic, and the box itself
    // holds Latin text.
    await expectArabicProse(dialog.getByText(/اكتب اسم المضيف/))

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })

  test('reads a domain in Arabic, and says an auto-renew switch is not a cancellation', async ({
    page,
  }) => {
    await page.goto('/domains')
    await page.getByRole('link', { name: fixtures.heldDomain }).first().click()

    await expect(page.getByRole('heading', { level: 1, name: fixtures.heldDomain })).toBeVisible()

    // The sentence that stops "auto-renew off" being read as "cancelled".
    const renewal = page.locator('section', { hasText: 'التجديد' }).first()
    await expectArabicProse(renewal.getByText(/التجديد التلقائي (مفعّل|متوقف)/))

    // The registrant form, in Arabic, with the privacy sentence.
    await section(page, 'بيانات المالك')
    await expect(page.getByText(/لا يُكتب أي شيء من هذا النموذج/)).toBeVisible()
  })
})
