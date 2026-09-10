import { expect, test, type Page } from '@playwright/test'

import { documentLanguage, fixtures, signIn, users } from './support/helpers'

/*
 * How the portal looks, asserted in a browser because none of it is decoration.
 *
 * A dark palette that never applies, a page that does not mirror for Arabic, an
 * IP address rendered right to left, an amount printed in Eastern Arabic
 * numerals — every one of these is a correctness problem for the person reading
 * the screen, and none of them can be caught by a test that renders components
 * into a headless DOM with no stylesheet, no locale negotiation and no layout.
 */

/** The colour the browser actually paints behind the page. */
async function pageBackground(page: Page): Promise<string> {
  return page.evaluate(() => getComputedStyle(document.documentElement).backgroundColor)
}

/**
 * How light the painted colour is, 0 (black) to 1 (white).
 *
 * The tokens are authored in oklch and the browser reports them back in oklch,
 * whose first component *is* lightness. An earlier version of this helper fed
 * that string through an sRGB luminance formula, which read the hue as a blue
 * channel and returned ~0.07 for both palettes — so the dark assertion passed
 * no matter what the page did. Both formats are handled, and the two schemes
 * are compared against each other below so that a helper that lies again
 * cannot pass quietly.
 */
function lightness(colour: string): number {
  const numbers = (colour.match(/-?\d*\.?\d+/g) ?? []).map(Number)

  if (colour.startsWith('oklch')) {
    const value = numbers[0] ?? 0

    return colour.includes('%') ? value / 100 : value
  }

  const [r = 0, g = 0, b = 0] = numbers

  return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
}

test.describe('colour scheme', () => {
  test('the palette follows the visitor preference in both directions', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/sign-in')
    const light = await pageBackground(page)

    await page.emulateMedia({ colorScheme: 'dark' })
    const dark = await pageBackground(page)

    expect(lightness(light)).toBeGreaterThan(0.85)
    expect(lightness(dark)).toBeLessThan(0.35)
    // Asserted against each other as well as against thresholds: a measurement
    // that cannot tell the two palettes apart is not a measurement.
    expect(dark).not.toBe(light)
  })

  test('the preference survives navigation into the signed-in portal', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await signIn(page)

    expect(lightness(await pageBackground(page))).toBeLessThan(0.35)
  })
})

test.describe('Arabic layout', () => {
  test.use({ locale: 'ar' })

  test('the signed-in portal stays right to left', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
  })

  test('technical values are not mirrored on a right-to-left page', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/vps')

    const hostname = page.getByText(fixtures.vpsHostname).first()
    await expect(hostname).toBeVisible()

    // A hostname or an address that inherits the page direction is read wrong:
    // "10.0.0.1/24" mirrored is a different address. The cell declares its own
    // direction rather than relying on the bidirectional algorithm to guess.
    const direction = await hostname.evaluate((node) => getComputedStyle(node).direction)
    expect(direction).toBe('ltr')
  })

  test('both rebuild warnings read in Arabic', async ({ page }) => {
    /*
     * The destructive sentences are the copy that must never fall back to
     * English: a customer reading the page in Arabic and confirming from a
     * half-translated dialogue has not been warned. Both screens are checked
     * in one spec because they are two different sentences on two different
     * pages, and shipping one translated and the other not is exactly the
     * failure that would go unnoticed.
     */
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await page.goto('/dedicated')
    await page.getByRole('link', { name: fixtures.dedicatedSerial }).click()
    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'منطقة الخطر' })
      .click()
    await page.getByRole('button', { name: /إعادة التثبيت/ }).first().click()

    const dedicated = page.getByRole('dialog')
    await expect(dedicated).toBeVisible()
    await expect(dedicated.getByText(/ستمسح نظام التشغيل المثبَّت/)).toBeVisible()

    await page.keyboard.press('Escape')

    await page.goto('/vps')

    // The machine by name, and the operable one: the seeder also creates a
    // suspended machine and one whose last rebuild nobody can settle, and
    // since Wave 0 every control on both is correctly disabled.
    await page.getByRole('link', { name: fixtures.operableHostname }).click()
    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'منطقة الخطر' })
      .click()
    await page.getByRole('button', { name: /إعادة التثبيت/ }).click()

    const vps = page.getByRole('dialog')
    await expect(vps).toBeVisible()
    await expect(vps.getByText(/سيتم استبدال قرص الخادم/)).toBeVisible()
  })

  test('money keeps Western numerals in Arabic', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
    await page.goto('/wallet')

    /*
     * 12.750 KWD, seeded. Eastern Arabic numerals are correct Arabic and wrong
     * here: the platform's invoices, the bank's statements and the payment
     * provider's receipts all print 12.750, and a customer comparing them
     * should not have to transliterate.
     *
     * Scoped to the balance card, because the credit history below it carries
     * the balance after each movement and the top row's is the same figure.
     */
    await expect(page.getByRole('listitem').getByText(/12\.750/)).toBeVisible()
  })
})
