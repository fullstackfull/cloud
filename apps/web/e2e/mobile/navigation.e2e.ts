import { expect, test } from '@playwright/test'

import { documentLanguage, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * The portal on a phone: every destination reachable, the language
 * switchable, the way out present. Run on a phone descriptor (viewport,
 * touch, mobile user agent), so the drawer is the only navigation on screen
 * and a destination the drawer does not offer is a destination this customer
 * cannot reach.
 */

/** What a phone customer must be able to open, with the heading each screen shows. */
const DESTINATIONS: Array<{ link: RegExp; heading: RegExp; path: string }> = [
  { link: /^cloud vps$/i, heading: /^cloud vps$/i, path: '/vps' },
  { link: /^dedicated servers$/i, heading: /^dedicated servers$/i, path: '/dedicated' },
  { link: /^shared hosting$/i, heading: /^shared hosting$/i, path: '/hosting' },
  { link: /^wordpress$/i, heading: /^wordpress$/i, path: '/wordpress' },
  { link: /^domains$/i, heading: /^domains$/i, path: '/domains' },
  { link: /^dns$/i, heading: /^dns$/i, path: '/dns' },
  { link: /^backups$/i, heading: /^backups$/i, path: '/backups' },
  { link: /^invoices$/i, heading: /^invoices$/i, path: '/invoices' },
  { link: /^subscriptions$/i, heading: /^subscriptions$/i, path: '/subscriptions' },
  { link: /^wallet/i, heading: /^wallet/i, path: '/wallet' },
  { link: /^support$/i, heading: /^support$/i, path: '/support' },
  { link: /^profile$/i, heading: /^profile$/i, path: '/profile' },
  { link: /^security$/i, heading: /^security$/i, path: '/security' },
  { link: /^team$/i, heading: /^team$/i, path: '/settings/team' },
  { link: /^api keys$/i, heading: /^api (keys|tokens)$/i, path: '/api-tokens' },
  { link: /^notifications$/i, heading: /^notifications$/i, path: '/notifications' },
  { link: /^orders$/i, heading: /^orders$/i, path: '/orders' },
  { link: /^ip addresses$/i, heading: /^ip addresses$/i, path: '/ips' },
  { link: /^services$/i, heading: /^services$/i, path: '/services' },
  { link: /^catalogue$/i, heading: /^catalogue$/i, path: '/catalogue' },
]

test.describe('a customer on a phone', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('the hamburger is announced as the menu, and the desktop bar is not on screen', async ({ page }) => {
    await expect(page.getByRole('button', { name: /^menu$/i })).toBeVisible()
    await expect(page.getByRole('button', { name: /^dashboard$/i })).toHaveCount(0)
    // The desktop navigation is hidden at this width, so the drawer is the way.
    await expect(page.getByRole('navigation', { name: /main navigation/i })).toBeHidden()
  })

  test('reaches every customer destination from the drawer, which closes behind each choice', async ({ page }) => {
    for (const destination of DESTINATIONS) {
      const drawer = await openMenu(page)
      const link = drawer.getByRole('link', { name: destination.link })
      // A destination below the fold is still a destination: the drawer scrolls.
      await link.scrollIntoViewIfNeeded()
      await expect(link).toBeVisible()
      await link.click()

      await expect(page).toHaveURL(new RegExp(destination.path.replace(/\//g, '\\/') + '(\\?|$)'))
      await expect(page.getByRole('heading', { name: destination.heading }).first()).toBeVisible()
      await expect(page.getByRole('dialog', { name: /^menu$/i })).toBeHidden()
    }
  })

  test('marks the current destination, closes on Escape and on the backdrop, and never lists operator screens', async ({
    page,
  }) => {
    await page.goto('/support')
    let drawer = await openMenu(page)
    await expect(drawer.getByRole('link', { name: /^support$/i })).toHaveAttribute('aria-current', 'page')
    await expect(drawer.getByRole('link', { name: /^customers$/i })).toHaveCount(0)
    await expect(drawer.getByRole('link', { name: /^infrastructure$/i })).toHaveCount(0)

    // Escape: the browser's own dialog behaviour, wired to the menu state.
    await page.keyboard.press('Escape')
    await expect(drawer).toBeHidden()
    await expect(page.getByRole('button', { name: /^menu$/i })).toHaveAttribute('aria-expanded', 'false')

    // The page behind is inert while the drawer is open: the sign-out in the
    // header cannot be reached, only the one inside the drawer.
    drawer = await openMenu(page)
    await expect(page.getByRole('button', { name: /^menu$/i })).toHaveAttribute('aria-expanded', 'true')
    await page.mouse.click(380, 600)
    await expect(drawer).toBeHidden()
  })

  test('switches to Arabic and back from inside the drawer without leaving the page or the session', async ({ page }) => {
    await page.goto('/invoices')
    let drawer = await openMenu(page)

    await drawer.getByRole('button', { name: 'العربية' }).click()
    await expect(page).toHaveURL(/\/invoices$/)
    expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
    // The drawer stays open, now in Arabic, with the same destination current.
    drawer = page.getByRole('dialog', { name: 'القائمة' })
    await expect(drawer).toBeVisible()
    await expect(drawer.getByRole('link', { name: 'الفواتير' })).toHaveAttribute('aria-current', 'page')
    await drawer.getByRole('button', { name: 'إغلاق' }).click()
    await expect(page.getByRole('heading', { name: 'الفواتير' })).toBeVisible()

    drawer = await openMenu(page, /^القائمة$/)
    await drawer.getByRole('button', { name: 'English' }).click()
    expect(await documentLanguage(page)).toEqual({ lang: 'en', dir: 'ltr' })
    await expect(page).toHaveURL(/\/invoices$/)
    await expect(page.getByRole('dialog', { name: /^menu$/i })).toBeVisible()
  })

  test('signs out from the drawer', async ({ page }) => {
    const drawer = await openMenu(page)
    await drawer.getByRole('button', { name: /sign out/i }).click()
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()

    await page.goto('/wallet')
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
  })
})
