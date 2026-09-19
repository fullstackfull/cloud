import { expect, test, type Page } from '@playwright/test'

import { expectNoPageOverflow } from './support/layout'
import { fixtures, signIn, users } from './support/helpers'

/*
 * §2, §5. The rest of the matrix: 1440, 1024 and 768, in both languages.
 *
 * The two narrow widths have a project each (see e2e/narrow/, which runs at
 * 393 and at 360 on real phone descriptors). These three are the widths a
 * desktop project can measure by setting a viewport, and they are the ones
 * either side of the two breakpoints that matter: the sidebar appears at 1024,
 * and 768 is the tablet the drawer serves rather than a cramped column.
 *
 * ## What this catches, and what it does not
 *
 * Stated precisely, because it is easy to assume a width gate catches every
 * overflow and it does not. W5.8's headline defect — `sr-only` text escaping a
 * static scroll container — put a box at x≈541 to x≈666 depending on the
 * screen. That is *inside* a 768px viewport, let alone 1440, so this gate
 * cannot see it and does not claim to; reintroducing that defect leaves these
 * twelve tests green. The narrow project catches it, because the defect is a
 * defect only where the viewport is narrower than the escaped box.
 *
 * What this catches is the other family: something that is wide at every
 * width. Proven by removing `min-w-0` from the layout's flex child — the
 * single class AppLayout's own comment says stops a wide table stretching the
 * shell — whereupon /invoices dragged 13px sideways at 1024 and this gate
 * named the element and the width. It stayed green at 1440, which is correct:
 * 13px of extra table does not reach past a 1440px window.
 *
 * Arabic is measured as well as English, and not because the layout is
 * mirrored: label widths differ, so the widest thing on a screen is a
 * different thing in each language, and the sweep behind this wave found
 * screens that overflowed in one language and not the other.
 */

const WIDTHS: Array<[number, number]> = [
  [1440, 900],
  [1024, 800],
  [768, 1024],
]

/** Every screen a customer reaches by address alone. */
const DIRECT = [
  '/',
  '/activity',
  '/catalogue',
  '/services',
  '/vps',
  '/dedicated',
  '/hosting',
  '/wordpress',
  '/domains',
  '/dns',
  '/ips',
  '/backups',
  '/orders',
  '/invoices',
  '/payments',
  '/subscriptions',
  '/wallet',
  '/notifications',
  '/support',
  '/profile',
  '/settings/team',
  '/security',
  '/api-tokens',
  '/verify-email',
] as const

/** And the resource pages, which carry the widest tables in the portal. */
const BY_NAME = [
  ['/vps', fixtures.operableHostname, ['', 'networking', 'billing', 'danger']],
  ['/dedicated', fixtures.dedicatedSerial, ['', 'billing']],
  ['/hosting', fixtures.hostingDomain, ['', 'billing']],
  ['/wordpress', fixtures.liveSite, ['', 'copies']],
  ['/domains', fixtures.heldDomain, ['', 'contacts']],
  ['/dns', fixtures.heldDomain, ['', 'records']],
] as const

for (const language of ['en', 'ar'] as const) {
  test.describe(`every screen at every desktop width, in ${language}`, () => {
    test.describe.configure({ timeout: 600_000 })

    for (const [width, height] of WIDTHS) {
      /*
       * Two tests per width rather than one, and the split is not cosmetic.
       *
       * The API under this suite is `php artisan serve`, which is
       * single-threaded. One test walking all thirty-eight addresses fires
       * thirty-eight full document loads, each with several concurrent reads
       * behind it, and somewhere around the thirtieth the server stops
       * answering: the portal's auth guard then correctly reports that it
       * could not confirm the sign-in, and the assertion fails for a reason
       * that has nothing to do with layout. Splitting the walk keeps every
       * address covered and every assertion exact — it is the harness that is
       * given less to do at once, not the test.
       */
      test(`does not scroll sideways on an index at ${width.toString()}`, async ({ page }) => {
        await open(page, language, width, height)

        for (const address of DIRECT) {
          await page.goto(address)
          await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
          // A fixed settle rather than network-idle: the dashboard and the
          // feed poll, so "no requests for 500ms" never arrives on some of
          // these screens.
          await page.waitForTimeout(700)
          await expectNoPageOverflow(page, `${address} (${language}, ${width.toString()}px)`)
        }
      })

      test(`does not scroll sideways on a resource page at ${width.toString()}`, async ({ page }) => {
        await open(page, language, width, height)

        for (const [index, identity, sections] of BY_NAME) {
          await page.goto(index)
          await page.locator('main').getByRole('link', { name: identity, exact: true }).first().click()
          await page.waitForURL((url) => url.pathname !== index)
          const base = new URL(page.url()).pathname

          for (const section of sections) {
            await page.goto(section === '' ? base : `${base}/${section}`)
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
            await page.waitForTimeout(700)
            await expectNoPageOverflow(
              page,
              `${base}/${section} (${language}, ${width.toString()}px)`,
            )
          }
        }
      })
    }
  })
}

/** The width, the language, and a session — in that order, once per test. */
async function open(
  page: Page,
  language: 'en' | 'ar',
  width: number,
  height: number,
): Promise<void> {
  await page.setViewportSize({ width, height })

  if (language === 'ar') {
    await page.goto('/sign-in')
    await page.getByRole('button', { name: 'العربية' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  }

  await signIn(page, users.customer, {
    headingPattern: language === 'ar' ? /مرحب|أهل/ : /welcome/i,
  })
}
