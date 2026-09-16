import path from 'node:path'

import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The visual record: §2's matrix, written to e2e/.artifacts/captures/.
 *
 * Five widths and two languages over the screen set §3 asks for. What the
 * widths are for: 1440 and 1024 sit either side of the sidebar breakpoint,
 * 768 is the tablet the drawer serves rather than a cramped column, 393 is the
 * phone, and 360 is the narrowest width the portal claims — measured
 * separately from 393 because §4 says so and because a row that fits at 393
 * with four pixels to spare does not fit at 360.
 *
 * ## What this is not
 *
 * It is not the test. §20 and §21 are explicit, and they are right: a count of
 * PNGs is not a quality result, and a full-page pixel snapshot as the primary
 * gate is a gate that fails on every legitimate change and gets deleted. The
 * assertions about these widths are layout and overflow assertions, and they
 * live in e2e/narrow/, e2e/mobile/, e2e/arabic/ and wave-3.e2e.ts, all of
 * which run on every CI run. These images exist so a person can look.
 *
 * The one defect W5.8 found at 360 was invisible in every image of every
 * affected screen — an `sr-only` heading escaping a scroll container, drawn
 * nowhere, dragging the page 306px sideways. Screenshots would never have
 * found it. That is the reason this file is skipped by default and the
 * measurements are not.
 *
 * Run it with:
 *   CAPTURES=1 npx playwright test e2e/captures.e2e.ts --project=chromium
 */

const OUTPUT = path.resolve(import.meta.dirname, '.artifacts/captures')

const WIDTHS: Array<[string, number, number]> = [
  ['1440', 1440, 900],
  ['1024', 1024, 800],
  ['768', 768, 1024],
  ['393', 393, 851],
  ['360', 360, 740],
]

/** The screen set §3 names, and how to reach each one. */
const SCREENS: Array<{ name: string; index: string; identity?: string; section?: string }> = [
  { name: 'dashboard', index: '/' },
  { name: 'activity', index: '/activity' },
  { name: 'buy', index: '/catalogue' },
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
  { name: 'backups', index: '/backups' },

  { name: 'orders', index: '/orders' },
  { name: 'invoices', index: '/invoices' },
  { name: 'invoice', index: '/invoices', identity: fixtures.openInvoice },
  { name: 'payments', index: '/payments' },
  { name: 'subscriptions', index: '/subscriptions' },
  { name: 'wallet', index: '/wallet' },

  { name: 'notifications', index: '/notifications' },
  { name: 'support', index: '/support' },

  { name: 'profile', index: '/profile' },
  { name: 'team', index: '/settings/team' },
  { name: 'security', index: '/security' },
  { name: 'tokens', index: '/api-tokens' },
  { name: 'addresses', index: '/ips' },
  { name: 'verify-email', index: '/verify-email' },
]

/** And the screens a customer meets before there is a session. */
const PUBLIC = [
  { name: 'sign-in', index: '/sign-in' },
  { name: 'register', index: '/register' },
  { name: 'forgot-password', index: '/forgot-password' },
]

test.describe('the visual record', () => {
  test.skip(process.env.CAPTURES !== '1', 'captures are taken on request, not on every run')

  /*
   * One test per language and width, rather than one per language.
   *
   * Thirty-three screens is already several minutes of navigating and writing
   * full-page PNGs; three widths in one test was long enough that the portal's
   * own session had gone by the end of it, and the failure that followed said
   * nothing about the portal. Ten short recordings, each signing in for
   * itself, fail where the fault is.
   */
  test.describe.configure({ timeout: 900_000 })

  for (const language of ['en', 'ar'] as const) {
    for (const [label, width, height] of WIDTHS) {
      test(`records every screen in ${language} at ${label}`, async ({ page }) => {
        await page.setViewportSize({ width, height })

        if (language === 'ar') {
          /*
           * Chosen on the sign-in screen, through the portal's own control,
           * before there is a session to carry the choice: the capture is then
           * of the portal an Arabic-speaking customer actually meets, from the
           * first screen onward.
           */
          await page.goto('/sign-in')
          await page.getByRole('button', { name: 'العربية' }).first().click()
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
        }

        const shoot = async (name: string): Promise<void> => {
          // Settled rather than raced: a capture taken mid-request records a
          // loading state and proves nothing about the layout.
          await page.waitForTimeout(1200)
          await page.screenshot({
            path: path.join(OUTPUT, `${language}-${label}-${name}.png`),
            fullPage: true,
          })
        }

        for (const screen of PUBLIC) {
          await page.goto(screen.index)
          await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
          await shoot(screen.name)
        }

        await signIn(page, users.customer, {
          headingPattern: language === 'ar' ? /مرحب|أهل/ : /welcome/i,
        })

        for (const screen of SCREENS) {
          await page.goto(screen.index)

          if (screen.identity !== undefined) {
            await page
              .locator('main')
              .getByRole('link', { name: screen.identity, exact: true })
              .first()
              .click()
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

            if (screen.section !== undefined) {
              await page.goto(`${new URL(page.url()).pathname}/${screen.section}`)
            }
          }

          await shoot(screen.name)
        }

        /*
         * The four states §3 asks for beyond the screens themselves. Each is
         * reached by driving the product rather than by mocking: the drawer is
         * opened, the dialogue is opened on a real machine, and the empty
         * state is a genuinely empty list.
         */
        if (width < 1024) {
          await page.goto('/')
          await page.getByRole('button', { name: /^menu$|^القائمة$/i }).click()
          await expect(page.getByRole('dialog')).toBeVisible()
          await shoot('drawer')
          await page.keyboard.press('Escape')
        }

        await page.goto('/vps')
        await page.locator('main').getByRole('link', { name: fixtures.operableHostname, exact: true }).click()
        await page.goto(`${new URL(page.url()).pathname}/danger`)
        await page.getByRole('button').filter({ hasText: /reinstall|إعادة/i }).first().click()
        await expect(page.getByRole('dialog')).toBeVisible()
        await shoot('confirmation-dialog')
        await page.keyboard.press('Escape')

        // An empty list: this account holds no dedicated-server backups, so
        // the backups screen filtered to them has nothing to show.
        await page.goto('/orders?page=9999')
        await shoot('empty-state')

        // And a refused read, driven through the address rather than stubbed:
        // an invoice that is not this account's answers 404, and the screen
        // must say so rather than render as empty.
        await page.goto('/invoices/01JQQQQQQQQQQQQQQQQQQQQQQQ')
        await shoot('failure-state')

        /*
         * The loading state, which is the one state that cannot be captured by
         * arriving somewhere and waiting: by the time a screenshot is taken it
         * is gone. So the response is held for a few seconds — the request is
         * still made and still answered, nothing is stubbed — and the picture
         * is taken while the screen is genuinely waiting.
         *
         * Worth a capture of its own since W5.8: until this wave the waiting
         * state and the empty state were the same picture, and the only way to
         * see that they now differ is to look at them side by side.
         */
        await page.route('**/api/v1/invoices*', async (route) => {
          await new Promise((resolve) => setTimeout(resolve, 4000))
          await route.continue()
        })
        await page.goto('/invoices')
        await expect(page.getByRole('status')).toBeVisible()
        await page.screenshot({
          path: path.join(OUTPUT, `${language}-${label}-loading-state.png`),
          fullPage: true,
        })
        await page.unroute('**/api/v1/invoices*')
      })
    }
  }
})
