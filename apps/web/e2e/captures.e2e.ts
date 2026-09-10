import path from 'node:path'

import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The visual record: every screen this wave changed, at the three widths and
 * in both languages, written to e2e/.artifacts/captures/.
 *
 * Skipped unless CAPTURES=1. It is a recording pass rather than an assertion
 * pass — nothing here can fail in a way that means the product is wrong — and
 * a suite that spent two minutes writing PNGs nobody reads on every CI run
 * would be paying for the record twice. The assertions about these widths
 * live in wave-3.e2e.ts, mobile/resource-pages.e2e.ts and
 * arabic/resource-pages.e2e.ts, which do run every time.
 *
 * Run it with:
 *   CAPTURES=1 npx playwright test e2e/captures.e2e.ts --project=chromium
 */

const OUTPUT = path.resolve(import.meta.dirname, '.artifacts/captures')

/** 1440 and 1024 sit either side of the sidebar breakpoint; 390 is the phone. */
const WIDTHS: Array<[string, number, number]> = [
  ['1440', 1440, 900],
  ['1024', 1024, 800],
  ['390', 390, 844],
]

/** Each screen the wave introduced or reshaped, and how to reach it. */
const SCREENS: Array<{ name: string; index: string; identity?: string; section?: string }> = [
  { name: 'services-index', index: '/services' },
  { name: 'vps-index', index: '/vps' },
  { name: 'vps-overview', index: '/vps', identity: fixtures.operableHostname },
  { name: 'vps-networking', index: '/vps', identity: fixtures.operableHostname, section: 'networking' },
  { name: 'vps-backups', index: '/vps', identity: fixtures.vpsHostname, section: 'backups' },
  { name: 'vps-billing', index: '/vps', identity: fixtures.operableHostname, section: 'billing' },
  { name: 'vps-danger', index: '/vps', identity: fixtures.operableHostname, section: 'danger' },
  { name: 'dedicated', index: '/dedicated', identity: fixtures.dedicatedSerial },
  { name: 'hosting', index: '/hosting', identity: fixtures.hostingDomain },
  { name: 'wordpress', index: '/wordpress', identity: fixtures.liveSite },
  { name: 'wordpress-copies', index: '/wordpress', identity: fixtures.liveSite, section: 'copies' },
  { name: 'domain', index: '/domains', identity: fixtures.heldDomain },
  { name: 'domain-contacts', index: '/domains', identity: fixtures.heldDomain, section: 'contacts' },
  { name: 'dns-zone', index: '/dns', identity: fixtures.heldDomain },
  { name: 'dns-records', index: '/dns', identity: fixtures.heldDomain, section: 'records' },
  { name: 'buy', index: '/catalogue' },
]

test.describe('the visual record', () => {
  test.skip(process.env.CAPTURES !== '1', 'captures are taken on request, not on every run')

  // Sixteen screens at three widths in two languages is a long single test.
  test.describe.configure({ timeout: 600_000 })

  for (const language of ['en', 'ar'] as const) {
    test(`captures every changed screen in ${language}`, async ({ page }) => {
      await signIn(page, users.customer, {
        headingPattern: language === 'ar' ? /مرحب|أهل/ : /welcome/i,
      })

      if (language === 'ar') {
        // Switched through the portal's own control, so the capture is of the
        // portal a customer would be looking at.
        await page.getByRole('button', { name: 'العربية' }).first().click()
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
      }

      for (const [label, width, height] of WIDTHS) {
        await page.setViewportSize({ width, height })

        for (const screen of SCREENS) {
          await page.goto(screen.index)

          if (screen.identity !== undefined) {
            await page.getByRole('link', { name: screen.identity, exact: true }).first().click()
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

            if (screen.section !== undefined) {
              await page.goto(`${page.url()}/${screen.section}`)
            }
          }

          // Settled rather than raced: a capture taken mid-request records a
          // loading state and proves nothing about the layout.
          await page.waitForLoadState('networkidle')

          await page.screenshot({
            path: path.join(OUTPUT, `${language}-${label}-${screen.name}.png`),
            fullPage: true,
          })
        }
      }
    })
  }
})
