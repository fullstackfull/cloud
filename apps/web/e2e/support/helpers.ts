import { execFileSync } from 'node:child_process'
import path from 'node:path'

import { expect, type Page } from '@playwright/test'

/**
 * The accounts E2ESeeder creates. Fixed values on purpose: a browser test that
 * looks up "whatever user exists" asserts nothing about which one it found.
 */
export const users = {
  customer: { email: 'customer@lynomia.local', password: 'password' },
  operator: { email: 'admin@lynomia.local', password: 'password' },
  // Billing authority and nothing else — the login the boundary specs need,
  // since every check passes for a super admin.
  billingAdmin: { email: 'billing@lynomia.local', password: 'password' },
  noc: { email: 'noc@lynomia.local', password: 'password' },
} as const

/** Fixtures the specs assert on by name. Mirrors E2ESeeder's constants. */
export const fixtures = {
  vpsHostname: 'e2e-web-01',
  dedicatedSerial: 'E2E-SN-000117',
  openInvoice: 'INV-E2E-0001',
  paidInvoice: 'INV-E2E-0002',
  largeInvoice: 'INV-E2E-0003',
  suspendedHostname: 'e2e-suspended-01',
  reactivatingHostname: 'e2e-reactivating-01',
  vpsAddress: '198.51.100.24',
  hostingUsername: 'e2ehost',
  teammateEmail: 'teammate@lynomia.local',
  pendingInvitationEmail: 'invited@lynomia.local',
  ticketReference: 'LYN-E2E-000001',
  ticketSubject: 'Cannot reach my server over SSH',
  ticketInternalNote: 'do not mention the batch',
} as const

/**
 * Signs in through the real form.
 *
 * Not by posting to the API and injecting a cookie: the point of this suite is
 * that the browser gets a session at all — that the CSRF cookie is fetched,
 * that the origins match, that the cookie comes back on the next request. A
 * helper that skipped the form would skip the only part of sign-in that has
 * ever broken.
 */
/**
 * Clears the login rate limiter.
 *
 * Sign-in is limited per IP address, deliberately and tightly: a flood of
 * attempts from one address is what credential stuffing looks like. Every test
 * in this suite comes from the same address, so a run of a dozen sign-ins
 * exhausts an allowance meant to be spent by an attacker — a property of the
 * harness, not of the platform.
 *
 * Cleared here rather than raised in configuration, because raising it would
 * mean the browser suite ran against a portal with a different security
 * posture from the one that ships. The limiter itself stays under test in
 * tests/Feature/Security.
 */
function clearRateLimits(): void {
  execFileSync('php', ['artisan', 'cache:clear', '--no-interaction'], {
    cwd: path.resolve(import.meta.dirname, '../../../control-plane'),
    stdio: 'ignore',
    env: {
      ...process.env,
      APP_ENV: 'local',
      DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'lynomia_e2e',
    },
  })
}

export async function signIn(
  page: Page,
  who: { email: string; password: string } = users.customer,
  options: { headingPattern?: RegExp } = {},
): Promise<void> {
  clearRateLimits()

  await page.goto('/sign-in')

  // By field type rather than by label text: the same helper signs in on the
  // Arabic portal, where every label is a different string.
  await page.locator('input[type="email"]').fill(who.email)
  await page.locator('input[type="password"]').fill(who.password)
  await page.locator('form').getByRole('button').first().click()

  await expect(page.getByRole('heading', { name: options.headingPattern ?? /welcome/i })).toBeVisible()
}

/**
 * What the document says its language and direction are.
 *
 * Read from <html> rather than inferred from how something looks, because that
 * is what assistive technology and the browser's own text handling read.
 */
export async function documentLanguage(page: Page): Promise<{ lang: string; dir: string }> {
  return page.evaluate(() => ({
    lang: document.documentElement.lang,
    dir: document.documentElement.dir,
  }))
}
