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
  // A second super-admin, because a plan is not approved by the person who
  // planned it and the chain spec needs two people.
  secondOperator: { email: 'ops2@lynomia.local', password: 'password' },
  /*
   * The account the money journeys spend from.
   *
   * Paying an invoice is not a read: it moves a wallet balance, writes a
   * payment row and posts a notification, and every one of those is a fact a
   * later spec reads off the shared account. This login owns the four W2
   * invoices and its own credit, so the journeys that spend money spend their
   * own. See E2ESeeder::moneyJourneys.
   */
  moneyCustomer: { email: 'money@lynomia.local', password: 'password' },
} as const

/** Fixtures the specs assert on by name. Mirrors E2ESeeder's constants. */
export const fixtures = {
  vpsHostname: 'e2e-web-01',
  // The machine with nothing unresolved against it: the one the power,
  // reinstall and plan-change specs actually drive. e2e-web-01 carries a
  // rebuild nobody can settle, so its controls are (correctly) off.
  operableHostname: 'e2e-app-02',
  dedicatedSerial: 'E2E-SN-000117',
  openInvoice: 'INV-E2E-0001',
  paidInvoice: 'INV-E2E-0002',
  largeInvoice: 'INV-E2E-0003',
  /*
   * One invoice per journey that spends money. The browser projects share a
   * database and run one after another, so a journey that settles a shared
   * fixture breaks the next project rather than its own spec.
   */
  creditThenCardInvoice: 'INV-E2E-W2-0001',
  declineInvoice: 'INV-E2E-W2-0002',
  phonePaymentInvoice: 'INV-E2E-W2-0003',
  arabicDeclineInvoice: 'INV-E2E-W2-0004',
  suspendedHostname: 'e2e-suspended-01',
  reactivatingHostname: 'e2e-reactivating-01',
  vpsAddress: '198.51.100.24',
  hostingUsername: 'e2ehost',
  teammateEmail: 'teammate@lynomia.local',
  pendingInvitationEmail: 'invited@lynomia.local',
  ticketReference: 'LYN-E2E-000001',
  ticketSubject: 'Cannot reach my server over SSH',
  ticketInternalNote: 'do not mention the batch',
  // Control Center. The first references a variable the E2E API process has;
  // the second references one it does not, so the screen has both states.
  credentialPresent: 'e2e-registrar-key',
  credentialMissing: 'e2e-bmc-password',
  credentialPresentReference: 'LYNOMIA_E2E_REGISTRAR_SECRET',
  // Machines and providers. The reachable machine has a fake BMC provider
  // with a credential the controller holds; the untouched one has nothing,
  // and no spec changes it. The blocked provider has no credential.
  machineReachable: 'e2e-node-01',
  machineUntouched: 'e2e-node-02',
  providerTestable: 'e2e-bmc-node-01',
  providerBlocked: 'e2e-dns',
  machineConfigurable: 'e2e-node-03',
  rack: 'E2E-R1',
  datacenter: 'Kuwait Central 1',
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

/**
 * Removes the seeded customer's second factor.
 *
 * The one spec that turns two-factor authentication on turns it off again at
 * its end, but a spec that fails halfway would leave every later sign-in in
 * the suite stuck at a code prompt. Cleared directly rather than through the
 * UI, because the UI is the thing that may have just failed.
 */
export function resetTwoFactor(): void {
  execFileSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      'Lynomia\\Modules\\Identity\\Infrastructure\\Models\\User::query()->where("email", "customer@lynomia.local")->update(["two_factor_secret" => null, "two_factor_recovery_codes" => null, "two_factor_confirmed_at" => null]);',
    ],
    {
      cwd: path.resolve(import.meta.dirname, '../../../control-plane'),
      stdio: 'ignore',
      env: { ...process.env, APP_ENV: 'local', DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'lynomia_e2e' },
    },
  )
}

/**
 * Drops every stored session for an account.
 *
 * The security screen lists a hundred sessions at most, and a suite-long run
 * of sign-ins fills that list: by the time the sign-out spec runs, revoking
 * one row lets an older session slide into view and the count does not move.
 * That is a fact about the harness — one browser signing in two hundred times
 * — and not about the platform, so the spec that counts rows arranges the
 * number it starts from instead of inheriting it.
 *
 * Done in the database rather than through the screen, because the screen is
 * the thing under test.
 */
export function forgetSessions(email: string = users.customer.email): void {
  execFileSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      '\\Illuminate\\Support\\Facades\\DB::table("sessions")->whereIn("user_id", ' +
        'Lynomia\\Modules\\Identity\\Infrastructure\\Models\\User::query()' +
        `->where("email", "${email}")->pluck("id"))->delete();`,
    ],
    {
      cwd: path.resolve(import.meta.dirname, '../../../control-plane'),
      stdio: 'ignore',
      env: { ...process.env, APP_ENV: 'local', DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'lynomia_e2e' },
    },
  )
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
