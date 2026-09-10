import { expect, test, type Page } from '@playwright/test'

import { documentLanguage, fixtures, signIn, users } from '../support/helpers'
import { expectArabicProse } from '../support/language'

/**
 * The money screens in Arabic, in a browser whose language is Arabic.
 *
 * Two things go wrong here and nowhere else: an amount that mirrors — a total
 * read right to left is a total somebody reads wrong — and a sentence that is
 * still in English because it came from a provider or from a code path nobody
 * translated.
 */

/** The same welcome the rest of the Arabic suite matches on. */
const WELCOME = /مرحب|أهل/

async function signInArabic(
  page: Page,
  who: { email: string; password: string } = users.customer,
): Promise<void> {
  await signIn(page, who, { headingPattern: WELCOME })

  // Read from the document rather than inferred from how it looks: this is
  // what assistive technology and the browser's own text handling read.
  expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
}

test.describe('an invoice in Arabic', () => {
  test('the document is in Arabic and the amounts are not mirrored', async ({ page }) => {
    await signInArabic(page)

    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.openInvoice) })
    await row.getByRole('link').first().click()

    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.openInvoice) }),
    ).toBeVisible()

    // The document's own headings, in Arabic prose rather than in English.
    await expectArabicProse(page.getByRole('heading', { level: 2 }).first())

    /*
     * An amount is Latin-numeral and left to right even on an Arabic page,
     * which is what MoneyText exists to guarantee. Read from the element
     * rather than from how it looks.
     */
    const amount = page.locator('[dir="ltr"]', { hasText: /\d+\.\d{3}/ }).first()
    await expect(amount).toBeVisible()
    await expect(amount).toHaveAttribute('dir', 'ltr')
  })

  test('a refused payment explains itself in Arabic', async ({ page }) => {
    // The money account: a refusal writes a failed payment and a notification
    // onto whichever account it is attempted from.
    await signInArabic(page, users.moneyCustomer)

    await page.goto('/invoices')

    const row = page.getByRole('row', { name: new RegExp(fixtures.arabicDeclineInvoice) })
    await row.getByRole('link').first().click()

    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.arabicDeclineInvoice) }),
    ).toBeVisible()

    // Pay, then decline on the provider's page.
    await page.getByRole('button').filter({ hasText: /دفع|سدد|ادفع/ }).first().click()

    await expect(page).toHaveURL(/\/fake-gateway\/authorise\//)

    await page.getByRole('button').filter({ hasText: /رفض/ }).first().click()

    await expect(page).toHaveURL(/\/invoices\//)

    // The reason is the portal's Arabic sentence, not the provider's English
    // code and not the provider's English prose.
    await expect(page.getByText(/رفض البنك البطاقة/)).toBeVisible()
  })
})

test.describe('the catalogue price in Arabic', () => {
  test('the breakdown names the setup fee and the renewal in Arabic', async ({ page }) => {
    await signInArabic(page)

    await page.goto('/catalogue')
    await page.getByRole('link').filter({ hasText: /الخطط|عرض/ }).first().click()

    await page.getByRole('button').filter({ hasText: /شهري/ }).first().click()

    await expect(page.getByText(/المستحق الآن/)).toBeVisible()
    await expect(page.getByText(/ثم شهري/)).toBeVisible()
    await expect(page.getByText(/لا يشمل رسوم التأسيس/)).toBeVisible()
  })
})

test.describe('the verification page in Arabic', () => {
  test('it explains what to do and offers the link again', async ({ page }) => {
    await page.goto('/verify-email')

    await expect(page.getByRole('heading', { level: 2, name: /تأكيد بريدك/ })).toBeVisible()
    await expectArabicProse(page.getByText(/افتح الرابط/))

    // Signed out, the page points at signing in rather than at a resend the
    // server would refuse.
    await expect(page.getByRole('link', { name: /تسجيل الدخول/ })).toBeVisible()
  })
})
