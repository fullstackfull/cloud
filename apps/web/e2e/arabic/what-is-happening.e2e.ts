import { expect, test, type Page } from '@playwright/test'

import { documentLanguage, fixtures, signIn, users } from '../support/helpers'
import { expectArabicProse } from '../support/language'

/*
 * §63. Wave 4's three surfaces, read by an Arabic customer.
 *
 * Everything this wave added assembles sentences from server-sent codes and
 * from Intl — a feed row, an attention row, an acknowledgement, a relative
 * time — and every one of those is a place where an untranslated key or a
 * Western-ordered phrase would reach exactly the reader it matters most to.
 * The catalogue gate in the API suite proves the strings exist; this proves
 * they are what the page renders, right to left, in a browser set to Arabic.
 */

const WELCOME = /مرحب|أهل/

async function signInArabic(page: Page): Promise<void> {
  await signIn(page, users.customer, { headingPattern: WELCOME })
  expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
}

test.describe('an Arabic customer reads what is happening', () => {
  test.beforeEach(async ({ page }) => {
    await signInArabic(page)
  })

  test('reads the dashboard, attention first and in Arabic', async ({ page }) => {
    const attention = page.getByRole('region', { name: 'يحتاج إلى انتباهك' })
    await expect(attention).toBeVisible()

    /*
     * Arabic prose, not a dotted key. The failure this catches is the exact
     * one the English run found on the first attempt: `attention.domain.lapsed`
     * rendered as itself at the top of the page, because the catalogue had no
     * string for it.
     */
    await expectArabicProse(attention.getByRole('listitem').first())

    // And nowhere on the page is a translation key showing through.
    const body = await page.locator('main').innerText()
    expect(body).not.toMatch(/\battention\.[a-z]/i)
    expect(body).not.toMatch(/\bactivity\.[a-z]/i)
  })

  test('reads the account feed in Arabic, with Arabic relative time', async ({ page }) => {
    await page
      .getByRole('navigation', { name: 'التنقّل الرئيسي' })
      .first()
      .getByRole('link', { name: 'النشاط' })
      .click()

    await expect(page.getByRole('heading', { level: 1, name: 'النشاط' })).toBeVisible()

    const rows = page.getByRole('listitem')
    await expect(rows.first()).toBeVisible()

    await expectArabicProse(rows.first().getByRole('paragraph').first())

    /*
     * "3 hours ago" comes from Intl.RelativeTimeFormat rather than from a
     * string table, which is the only way Arabic's six plural forms come out
     * right. What is asserted is that the rendered time is Arabic script —
     * an English "3 hours ago" here would mean the formatter was handed the
     * wrong locale.
     */
    const when = rows.first().locator('time').first()
    await expect(when).toBeVisible()
    expect(await when.innerText()).toMatch(/\p{Script=Arabic}/u)
  })

  test('acknowledges a reboot in Arabic, in the lifecycle s own words', async ({ page }) => {
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    await page.getByRole('button', { name: 'إعادة تشغيل' }).first().click()

    const channel = page.getByRole('region', { name: 'التحديثات' })
    await expect(channel).toBeVisible()

    // "تم طلب إعادة التشغيل" — the request was received, which is all anybody
    // knows yet. Asserted as Arabic prose rather than as an exact string so
    // that a better translation is not a failing test.
    await expectArabicProse(channel.getByRole('listitem').first())
  })

  test('offers support about a stopped operation, in Arabic', async ({ page }) => {
    await page
      .getByRole('navigation', { name: 'التنقّل الرئيسي' })
      .first()
      .getByRole('link', { name: 'النشاط' })
      .click()

    const ask = page.getByRole('link', { name: 'اسأل الدعم عن هذا' }).first()
    await expect(ask).toBeVisible()

    await ask.click()

    // The draft is in the language the customer is reading now, because what
    // travels in the link is a key rather than a sentence.
    const subject = page.getByLabel('الموضوع')
    expect(await subject.inputValue()).toMatch(/\p{Script=Arabic}/u)
  })
})
