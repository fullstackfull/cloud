import { expect, test, type Page } from '@playwright/test'

import { documentLanguage, fixtures, signIn, users } from '../support/helpers'
import { expectArabicProse } from '../support/language'

/*
 * The portal as an Arabic customer uses it: signed in with the browser's own
 * language set to Arabic, driving real journeys through the real API, and
 * reading every refusal in Arabic.
 *
 * Latin is allowed where Latin is right — a hostname, an invoice number, an
 * address — and forbidden where it is prose. `expectArabicProse` tells the
 * two apart (see support/language.ts).
 */

const WELCOME = /مرحب|أهل/

async function signInArabic(page: Page): Promise<void> {
  await signIn(page, users.customer, { headingPattern: WELCOME })
  expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
}

/** Every alert on the page is Arabic prose. */
async function everyAlertIsArabic(page: Page): Promise<void> {
  for (const alert of await page.getByRole('alert').all()) {
    if (await alert.isVisible()) await expectArabicProse(alert)
  }
}

test.describe('an Arabic customer', () => {
  test.beforeEach(async ({ page }) => {
    await signInArabic(page)
  })

  test('navigates the portal in Arabic from the sidebar, with nothing behind a disclosure', async ({ page }) => {
    const sidebar = page.getByRole('navigation', { name: 'التنقّل الرئيسي' }).first()
    await expect(sidebar).toBeVisible()

    await sidebar.getByRole('link', { name: 'شراء' }).click()
    await expect(page.getByRole('heading', { name: 'شراء' })).toBeVisible()

    /*
     * Straight to Security, with no "المزيد" to open first: Wave 3 replaced
     * the desktop disclosure that hid seventeen destinations with a
     * persistent column, and this is the Arabic side of that gate.
     */
    await expect(page.getByText('المزيد', { exact: true })).toHaveCount(0)

    await sidebar.getByRole('link', { name: 'الأمان' }).click()
    await expect(page.getByRole('heading', { name: 'الأمان' })).toBeVisible()
    await expect(page).toHaveURL(/\/security$/)
  })

  test('reads the catalogue in Arabic with prices in Western numerals', async ({ page }) => {
    await page.goto('/catalogue')
    await expect(page.getByRole('heading', { name: 'شراء' })).toBeVisible()
    await page.getByRole('link', { name: 'عرض الخطط' }).first().click()
    // Three-decimal KWD amounts in Western numerals; Intl puts the code after
    // the number in Arabic, so the two are asserted separately.
    await expect(page.getByText(/\d+\.\d{3}/).first()).toBeVisible()
    await expect(page.getByText(/KWD/).first()).toBeVisible()
    await expect(page.locator('main')).not.toContainText(/[٠-٩]/)
  })

  test('operates a machine in Arabic: a shutdown goes through, a force off asks first, and the stranded machine says why it is off', async ({
    page,
  }) => {
    await page.goto('/vps')
    await expect(page.getByRole('heading', { name: 'الخوادم السحابية' })).toBeVisible()

    const row = page.getByRole('row').filter({ hasText: fixtures.operableHostname })
    // The hostname stays Latin and left-to-right inside the Arabic row.
    await expect(row.locator('.technical, [dir="ltr"]').filter({ hasText: fixtures.operableHostname }).first()).toBeVisible()

    // Onto the machine's own page, which since Wave 3 is where the power
    // controls are.
    await row.getByRole('link', { name: fixtures.operableHostname }).click()
    await expect(page.getByRole('heading', { level: 1, name: fixtures.operableHostname })).toBeVisible()

    const shutdown = page.waitForResponse((r) => r.url().endsWith('/power') && r.request().postData()?.includes('shutdown') === true)
    await page.getByRole('button', { name: 'إيقاف آمن' }).click()
    expect((await shutdown).status()).toBe(202)
    await expect(page.getByText('متوقف', { exact: true }).first()).toBeVisible()

    await page.getByRole('button', { name: 'تشغيل', exact: true }).click()
    await expect(page.getByText('قيد التشغيل', { exact: true }).first()).toBeVisible()

    /*
     * Force off asks, in Arabic, and cancel leaves the machine alone.
     *
     * The word is the same one the confirmation's own button uses. It was
     * not: in English both say "Force off", while the Arabic said one thing
     * on the page ("فصل قسري") and another in the dialogue ("إيقاف قسري"),
     * so the confirmation appeared to be about a different action from the
     * one pressed. The pair now reads "إيقاف آمن" and "إيقاف قسري" — a safe
     * stop and a forced one.
     */
    await page.getByRole('button', { name: 'إيقاف قسري', exact: true }).click()
    const dialog = page.getByRole('dialog')
    await expectArabicProse(dialog)
    await dialog.getByRole('button', { name: 'إلغاء' }).click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText('قيد التشغيل', { exact: true }).first()).toBeVisible()

    // The machine whose rebuild nobody can settle: controls off, reason in
    // Arabic, on its own page.
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.vpsHostname }).click()

    await expect(page.getByRole('button', { name: 'إيقاف آمن' })).toBeDisabled()
    await expectArabicProse(page.getByText(/فريقنا|معطّلة/).first())

    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'منطقة الخطر' })
      .click()

    await expect(page.getByRole('button', { name: 'إعادة التثبيت' })).toBeDisabled()
  })

  test('claims a DNS zone and is refused an unserviceable record in Arabic', async ({ page }) => {
    const domain = 'arabic-claimed.test'
    await page.goto('/dns')
    await page.getByLabel('النطاق').first().fill(domain)
    await page.getByRole('button', { name: 'إضافة نطاق' }).click()

    // The zone's own page, and its records section.
    await page.getByRole('link', { name: domain }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: domain })).toBeVisible()

    const sections = page.getByRole('navigation', { name: 'الأقسام' })
    await sections.getByRole('link', { name: 'السجلات' }).click()

    // A private address the zone cannot serve: the API refuses it, and the
    // refusal reaches the screen as an Arabic sentence, not the engineer's.
    await page.getByLabel(/الاسم/).first().fill('internal')
    await page.getByLabel('القيمة').fill('10.0.0.5')
    await page.getByRole('button', { name: 'إضافة سجل' }).click()
    const alert = page.getByRole('alert').first()
    await expect(alert).toBeVisible()
    await expectArabicProse(alert)

    // A public one goes through, and the status badge is Arabic.
    await page.getByLabel('القيمة').fill('203.0.113.9')
    await page.getByRole('button', { name: 'إضافة سجل' }).click()
    const row = page.getByRole('row').filter({ hasText: 'internal.' + domain })
    await expect(row).toHaveCount(1)
    await expect(row.getByText(/نشط|قيد الانتظار/)).toBeVisible()

    await sections.getByRole('link', { name: 'منطقة الخطر' }).click()
    await page.getByRole('button', { name: 'التخلّي عن النطاق' }).first().click()
    const dialog = page.getByRole('dialog')
    await expectArabicProse(dialog)
    await dialog.getByRole('textbox').fill(domain)
    await dialog.getByRole('button', { name: 'التخلّي عن النطاق' }).click()

    // Back on the index, with the zone gone from it.
    await expect(page).toHaveURL(/\/dns$/)
    await expect(page.getByRole('link', { name: domain })).toHaveCount(0)
  })

  test('searches for domains in Arabic and reads a taken name and a held name coherently', async ({ page }) => {
    await page.goto('/domains')
    await expect(page.getByRole('heading', { name: 'النطاقات' })).toBeVisible()

    await page.getByLabel('اسم النطاق').fill('already-taken.test')
    await page.getByRole('button', { name: 'بحث' }).click()
    const taken = page.getByRole('row').filter({ hasText: 'already-taken.test' })
    await expect(taken.getByText('محجوز', { exact: true })).toBeVisible()
    await expect(taken.getByRole('button', { name: 'شراء' })).toHaveCount(0)

    // The held domain's expiry is a coherent Arabic date: a named month, no
    // reordered slashes, Western digits.
    const expiry = page.getByText(/\d{2} \p{Script=Arabic}+ \d{4}/u).first()
    await expect(expiry).toBeVisible()
    await expect(page.locator('main')).not.toContainText(/\d{6}\//)
  })

  test('reads backups in Arabic with their states and dates', async ({ page }) => {
    await page.goto('/backups')
    await expect(page.getByRole('heading', { name: 'النسخ الاحتياطي' })).toBeVisible()
    await page.getByRole('combobox').selectOption({ label: fixtures.vpsHostname })

    // Both seeded states, as Arabic badges: the finished copy and the one
    // waiting for a person. Neither may read as an English word.
    const table = page.getByRole('table')
    await expect(table.getByRole('row').nth(1)).toBeVisible()
    await expect(table.getByText('نجحت', { exact: false }).or(table.getByText('مكتمل')).first()).toBeVisible()
    await expect(table.getByText('بحاجة إلى مراجعة')).toBeVisible()
    await expect(table).not.toContainText(/\b(succeeded|needs review|needs_review)\b/i)
    for (const badge of await table.locator('span.rounded-full').all()) {
      await expectArabicProse(badge)
    }
    // The date a copy was taken is a named month, never a reordered numeric date.
    await expect(table.getByText(/\d{2} \p{Script=Arabic}+ \d{4}/u).first()).toBeVisible()
    await expect(table).not.toContainText(/\d{6}\//)
    await everyAlertIsArabic(page)
  })

  test('reads invoices and the credit dialogue in Arabic', async ({ page }) => {
    await page.goto('/invoices')
    await expect(page.getByRole('heading', { name: 'الفواتير' })).toBeVisible()
    const row = page.getByRole('row').filter({ hasText: fixtures.largeInvoice })
    // The invoice number stays as issued.
    await expect(row.getByText(fixtures.largeInvoice)).toBeVisible()
    await expect(row.getByText(/مفتوحة|مستحقة|قيد الانتظار|غير مدفوعة/).first()).toBeVisible()

    await row.getByRole('button', { name: 'استخدام الرصيد' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('الرصيد المتاح')).toBeVisible()
    await expectArabicProse(dialog.getByRole('heading').first())
    await expect(dialog.getByText(/\d+\.\d{3}/).first()).toBeVisible()
    await dialog.getByRole('button', { name: /إلغاء|إغلاق/ }).first().click()
    await expect(dialog).toBeHidden()
  })

  test('opens support in Arabic and replies on a ticket', async ({ page }) => {
    await page.goto('/support')
    await expect(page.getByRole('heading', { name: /الدعم/ }).first()).toBeVisible()
    await page.getByRole('button', { name: fixtures.ticketSubject }).click()
    await page.getByLabel('ردّك').fill('ما زال الاتصال مرفوضًا.')
    await page.getByRole('button', { name: /^ردّ$|^رد$|إرسال/ }).first().click()
    await expect(page.getByText('ما زال الاتصال مرفوضًا.')).toBeVisible()
  })

  test('is refused in Arabic by the server when turning on two-factor with the wrong password', async ({ page }) => {
    await page.goto('/security')
    await expect(page.getByRole('heading', { name: 'المصادقة بعاملين' })).toBeVisible()

    await page.getByRole('button', { name: 'تفعيل المصادقة بعاملين' }).click()
    await page.getByLabel('كلمة المرور الحالية').first().fill('not-the-password')
    await page.getByRole('button', { name: 'متابعة' }).click()

    // The sentence is the API's, composed in Arabic because the request asked
    // for Arabic — not the portal's, and not the engineer's English.
    const refusal = page.getByText('كلمة المرور هذه غير صحيحة.')
    await expect(refusal).toBeVisible()
    await everyAlertIsArabic(page)
    await expect(page.locator('main')).not.toContainText(/That password is incorrect/)
  })
})
