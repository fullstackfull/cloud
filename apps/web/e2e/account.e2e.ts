import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * The screens a customer uses to look after their own account.
 *
 * These matter more than most: the security page is where somebody goes when
 * they think their account has been used by someone else, and a page that shows
 * an empty list there answers a frightening question with a false reassurance.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page, users.customer)
})

test('the security page shows the current session as a signed-in device', async ({ page }) => {
  await page.goto('/security')

  await expect(page.getByRole('heading', { name: /signed-in devices/i })).toBeVisible()
  // The browser driving this test is itself a session, so the list cannot
  // legitimately be empty here. "No active sessions" on this page would mean
  // the request failed or the query is wrong, and either way the customer is
  // being told something untrue about their own account.
  await expect(page.getByText(/no active sessions/i)).toHaveCount(0)
  await expect(page.getByText(/this device/i).first()).toBeVisible()
})

test('the security page shows the sign-in that just happened', async ({ page }) => {
  await page.goto('/security')

  // signIn() succeeded moments ago, so the history has at least one entry and
  // it is a successful one.
  await expect(page.getByText(/^signed in$/i).first()).toBeVisible()
})

test('two-factor authentication is offered and reports its real state', async ({ page }) => {
  await page.goto('/security')

  await expect(page.getByRole('heading', { name: /two-factor/i })).toBeVisible()
  // The seeded customer has no second factor, so the screen must offer to turn
  // it on rather than claim it is already protecting them.
  await expect(page.getByRole('button', { name: /turn on two-factor/i })).toBeVisible()
})

test('a new API token is shown exactly once', async ({ page }) => {
  await page.goto('/api-tokens')

  await page.getByLabel(/^name$/i).fill('e2e-token')
  // The account password as well as a name: a token outlives the session that
  // minted it, so a stolen session must not be able to issue one.
  await page.getByLabel(/current password/i).fill(users.customer.password)
  await page.getByRole('button', { name: /create token/i }).click()

  // The plaintext token appears with the warning that it will not be shown
  // again — the platform stores only a hash, so a screen that quietly failed to
  // show it would have handed the customer a credential they can never use.
  await expect(page.getByText(/only time it is shown/i)).toBeVisible()
  await expect(page.getByText('e2e-token').first()).toBeVisible()

  // And it is gone after a reload, because there is nothing left to show.
  await page.reload()
  await expect(page.getByText(/only time it is shown/i)).toHaveCount(0)
  await expect(page.getByText('e2e-token').first()).toBeVisible()
})

test('signing out ends the session for real', async ({ page }) => {
  await page.getByRole('button', { name: /sign out/i }).click()
  await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()

  // Not merely a redirect: the portal is asked for a protected screen again,
  // and the server must refuse the cookie it just invalidated.
  await page.goto('/wallet')
  await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
})
