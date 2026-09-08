import { expect, test } from '@playwright/test'

import { documentLanguage, signIn, users } from './support/helpers'

/*
 * Signing in, in a real browser.
 *
 * Everything here is invisible to the component tests in `src/`, which stub
 * fetch: whether the CSRF cookie is fetched before the first mutation, whether
 * the session cookie survives the origin the portal is served from, whether a
 * guard redirects before or after the page has already rendered.
 */

test.describe('sign-in', () => {
  test('a signed-out visitor is sent to the sign-in page', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
    await expect(page).toHaveURL(/\/sign-in/)
  })

  test('the guard keeps a signed-out visitor off the security page', async ({ page }) => {
    await page.goto('/security')

    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
    // Not merely redirected: nothing from the protected page may have painted.
    await expect(page.getByText(/sign-in history/i)).toHaveCount(0)
  })

  test('a correct password reaches the dashboard', async ({ page }) => {
    await signIn(page)

    await expect(page).toHaveURL(/\/$|\/dashboard/)
  })

  test('a wrong password is refused without saying which half was wrong', async ({ page }) => {
    await page.goto('/sign-in')

    await page.getByLabel(/email/i).fill(users.customer.email)
    await page.getByLabel(/password/i).fill('not-the-password')
    await page.getByRole('button', { name: /sign in/i }).click()

    const alert = page.getByRole('alert')
    await expect(alert).toBeVisible()

    // The message must not distinguish "no such account" from "wrong
    // password": either would tell an attacker which addresses are worth
    // trying.
    await expect(alert).not.toContainText(/no account|not found|unknown user/i)
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
  })

  test('an unknown address is refused the same way as a wrong password', async ({ page }) => {
    await page.goto('/sign-in')

    await page.getByLabel(/email/i).fill('nobody@lynomia.local')
    await page.getByLabel(/password/i).fill('not-the-password')
    await page.getByRole('button', { name: /sign in/i }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
  })

  test('signing out ends the session for real', async ({ page }) => {
    await signIn(page)

    await page.getByRole('button', { name: /sign out/i }).click()
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()

    // The session is gone on the server, not merely forgotten by the client:
    // navigating back to a protected page must not restore it.
    await page.goto('/security')
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
  })

  test('a signed-in customer is kept off the sign-in page', async ({ page }) => {
    await signIn(page)

    await page.goto('/sign-in')

    await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible()
  })
})

/*
 * Two describes rather than one with `test.use` in the middle. `test.use`
 * applies to the whole block it appears in, not to the tests after it — which
 * ran the English case with an Arabic browser and failed for a reason that had
 * nothing to do with the application.
 */
test.describe('language and direction, in English', () => {
  test('an English visitor gets a left-to-right document', async ({ page }) => {
    await page.goto('/sign-in')

    expect(await documentLanguage(page)).toEqual({ lang: 'en', dir: 'ltr' })
  })
})

test.describe('language and direction, in Arabic', () => {
  test.use({ locale: 'ar' })

  test('an Arabic visitor gets a right-to-left document from the first paint', async ({ page }) => {
    await page.goto('/sign-in')

    // Set on <html> rather than achieved with CSS alone: a screen reader
    // announcing Arabic content as English is the failure this prevents.
    expect(await documentLanguage(page)).toEqual({ lang: 'ar', dir: 'rtl' })
  })

  test('the sign-in form is in Arabic and still works', async ({ page }) => {
    await page.goto('/sign-in')

    // The heading is translated, not merely mirrored.
    await expect(page.getByRole('heading')).not.toHaveText(/sign in/i)

    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })
  })
})
