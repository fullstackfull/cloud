import { createHmac } from 'node:crypto'

import { expect, test, type Page } from '@playwright/test'

import { fixtures, forgetSessions, resetTwoFactor, signIn, users } from './support/helpers'

/*
 * Wave 0: every existing control does what it says.
 *
 * The audit found the portal sending its idempotency key in the request body
 * while the API read it from the header, so placing an order, every power
 * action, both reinstalls and a plan change were answered 422 — "please
 * correct the highlighted fields", with no field on the screen. Two-factor
 * authentication could not be turned on for the same shape of reason. Eight
 * disruptive actions fired on one click. These specs press each of those
 * controls in a real browser against the controlled providers and assert on
 * the outcome the customer sees, not on the request being sent.
 *
 * Everything here runs against fakes: the compute provider, the BMC, the
 * payment provider. No real machine is touched.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page)
})

/**
 * Opens one machine's own page, the way a customer does: from the index, by
 * name.
 *
 * Since Wave 3 a machine has an address of its own, and the controls that
 * interrupt or destroy it live there rather than in a table row. These Wave 0
 * specs still press the same buttons through the same mutation path; only the
 * place a customer finds them has moved.
 */
async function openMachine(page: Page, hostname: string): Promise<void> {
  await page.goto('/vps')
  await page.getByRole('link', { name: hostname, exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name: hostname })).toBeVisible()
}

/** Moves to one section of a resource page. The sections are links, not tabs. */
async function openSection(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name }).click()
}

/**
 * RFC 6238, as pragmarx/google2fa computes it: SHA-1, thirty-second steps,
 * six digits, base32 secret. Written out rather than pulled from a package
 * so the spec depends on nothing the platform does not.
 */
function totp(secret: string, at: number = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = secret
    .toUpperCase()
    .replace(/=+$/, '')
    .split('')
    .map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0'))
    .join('')
  const bytes = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)))

  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)))

  const digest = createHmac('sha1', bytes).update(counter).digest()
  const offset = (digest[digest.length - 1] ?? 0) & 0x0f
  const code = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000

  return code.toString().padStart(6, '0')
}

test.describe('placing and cancelling an order', () => {
  test('an order is placed from the catalogue, a repeat of the same submission is one order, and cancelling it asks first', async ({
    page,
  }) => {
    await page.goto('/catalogue')
    await page.getByRole('link', { name: /view plans/i }).first().click()

    await page.getByRole('button', { name: /monthly/i }).first().click()

    const placed = page.waitForRequest(
      (request) => request.url().includes('/api/v1/orders') && request.method() === 'POST',
    )
    await page.getByRole('button', { name: /place order/i }).click()
    const request = await placed

    // The key travelled as the header the API reads, and only there.
    const key = request.headers()['idempotency-key']
    expect(key).toMatch(/^[0-9a-f-]{36}$/)
    expect(request.postDataJSON()).not.toHaveProperty('idempotency_key')

    await expect(page.getByRole('heading', { name: /^order ord-/i })).toBeVisible()
    await expect(page.getByText(/awaiting payment/i)).toBeVisible()

    const orderId = page.url().split('/orders/')[1] ?? ''
    expect(orderId).not.toBe('')

    /*
     * The same submission again — same key, same basket — from inside the
     * signed-in page, as a retry after a dropped response would send it. The
     * API answers with the order that already exists and creates no second one.
     */
    const replay = await page.evaluate(
      async ({ headerKey, body }) => {
        const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
        const response = await fetch('/api/v1/orders', {
          method: 'POST',
          credentials: 'include',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': token,
            'Idempotency-Key': headerKey,
          },
          body,
        })
        return { status: response.status, body: (await response.json()) as { data: { id: string } } }
      },
      { headerKey: key ?? '', body: request.postData() ?? '{}' },
    )

    expect(replay.status).toBe(201)
    expect(replay.body.data.id).toBe(orderId)

    await page.goto('/orders')
    const number = (await page.getByRole('link', { name: /^ORD-/ }).first().innerText()).trim()
    await expect(page.getByRole('row').filter({ hasText: number })).toHaveCount(1)

    // Cancelling asks first, and backing out leaves the order exactly as it was.
    await page.goto(`/orders/${orderId}`)
    await page.getByRole('button', { name: /^cancel order$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/nothing has been charged/i)).toBeVisible()
    await dialog.getByRole('button', { name: /^keep order$/i }).click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText(/awaiting payment/i)).toBeVisible()

    await page.getByRole('button', { name: /^cancel order$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^cancel this order$/i }).click()

    await expect(page.getByText(/^cancelled$/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /^cancel order$/i })).toHaveCount(0)
  })
})

test.describe('operating a virtual machine', () => {
  test('every power control completes against the controlled hypervisor, and force off asks first', async ({
    page,
  }) => {
    await openMachine(page, fixtures.operableHostname)

    /*
     * The power state as the header reports it. It is stated twice on this
     * page — once beside the hostname, once in the overview facts — because a
     * customer scrolling to the facts should not have to scroll back up to
     * learn whether the machine is on. The header badge is the first of the
     * two in the document, and it is the one these assertions read.
     */
    const power = (state: RegExp) => page.getByText(state).first()

    await expect(power(/^running$/i)).toBeVisible()

    const accepted = (action: string) =>
      page.waitForResponse(
        (response) =>
          response.url().includes('/power') && response.request().method() === 'POST' && response.status() === 202
          && (response.request().postDataJSON() as { action: string }).action === action,
      )

    // Shut down: the guest is asked to close its files; the fake obliges.
    const shutdown = accepted('shutdown')
    await page.getByRole('button', { name: /^shut down$/i }).click()
    await shutdown
    await expect(power(/^stopped$/i)).toBeVisible()

    const start = accepted('start')
    await page.getByRole('button', { name: /^start$/i }).click()
    await start
    await expect(power(/^running$/i)).toBeVisible()

    // Force off asks, and backing out pulls no plug.
    await page.getByRole('button', { name: /^force off$/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/pulls the plug/i)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(power(/^running$/i)).toBeVisible()

    const stop = accepted('stop')
    await page.getByRole('button', { name: /^force off$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^force off$/i }).click()
    await stop
    await expect(power(/^stopped$/i)).toBeVisible()

    const startAgain = accepted('start')
    await page.getByRole('button', { name: /^start$/i }).click()
    await startAgain
    await expect(power(/^running$/i)).toBeVisible()

    const reboot = accepted('reboot')
    await page.getByRole('button', { name: /^reboot$/i }).click()
    await reboot
    await expect(power(/^running$/i)).toBeVisible()

    // And not one of those produced the field error the audit found.
    await expect(page.getByText(/correct the highlighted fields/i)).toHaveCount(0)
  })
})

test.describe('operating a dedicated server', () => {
  test('power actions and a rebuild are accepted by the controlled controller, and force off asks first', async ({
    page,
  }) => {
    /*
     * Since Wave 3 the chassis has an address of its own. The index keeps the
     * one power action a customer reaches for without thinking; everything
     * that interrupts or destroys is on the machine's own page, where its
     * serial is in the heading and the rebuild sits in a danger zone.
     */
    await page.goto('/dedicated')
    await page.getByRole('link', { name: fixtures.dedicatedSerial }).click()

    const heading = page.getByRole('heading', { level: 1, name: fixtures.dedicatedSerial })
    await expect(heading).toBeVisible()

    const accepted = (path: string) =>
      page.waitForResponse(
        (response) => response.url().includes(path) && response.request().method() === 'POST' && response.status() === 202,
      )

    const cycle = accepted('/power')
    await page.getByRole('button', { name: /^power cycle$/i }).click()
    await cycle
    await expect(page.getByRole('alert')).toHaveCount(0)

    await page.getByRole('button', { name: /^force off$/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/cuts the power at the chassis/i)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()

    // Accepted by the controller. The page keeps showing what the controller
    // last REPORTED, not what was just asked — the platform deliberately does
    // not pretend a chassis is off because it sent the instruction — so the
    // outcome asserted is the acceptance and the absence of any error.
    const off = accepted('/power')
    await page.getByRole('button', { name: /^force off$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^force off$/i }).click()
    await off
    await expect(page.getByRole('alert')).toHaveCount(0)

    const on = accepted('/power')
    await page.getByRole('button', { name: /^power on$/i }).click()
    await on
    await expect(page.getByRole('alert')).toHaveCount(0)

    // The rebuild: typed serial, accepted, and the page reports where it got to.
    await page
      .getByRole('navigation', { name: /sections/i })
      .getByRole('link', { name: /^danger zone$/i })
      .click()
    await page.getByRole('button', { name: /^reinstall$/i }).click()
    const rebuild = page.getByRole('dialog')
    await rebuild.getByRole('textbox').fill(fixtures.dedicatedSerial)
    const reinstall = accepted('/reinstall')
    await rebuild.getByRole('button', { name: /reinstall this server/i }).click()
    await reinstall
    await expect(rebuild).toBeHidden()
    await expect(page.getByText(/correct the highlighted fields/i)).toHaveCount(0)
  })
})

test.describe('changing plan', () => {
  test('an upgrade is quoted, confirmed and applied', async ({ page }) => {
    await page.goto('/subscriptions')

    /*
     * The operable machine's agreement, by name.
     *
     * Whichever row happened to come first was a coin toss, and since the
     * fixture began naming what each subscription pays for it has been the
     * wrong one: the other agreement runs e2e-web-01, whose rebuild nobody can
     * settle, so the platform correctly refuses to resize it and every option
     * on that screen is disabled. The subscription this spec is about is the
     * one whose server is quiet.
     */
    const options = page.waitForResponse((response) => response.url().includes('/plan-options'))
    await page
      .getByRole('row', { name: new RegExp(fixtures.operableHostname) })
      .getByRole('link', { name: /change plan/i })
      .click()
    await options

    // The first plan the platform would allow: refused ones (the current
    // plan among them) keep their button disabled.
    await page.getByRole('button', { name: /^choose$/i }).and(page.locator(':enabled')).first().click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/charged .* today/i)).toBeVisible()

    const applied = page.waitForResponse(
      (response) => response.url().endsWith('/plan') && response.request().method() === 'POST',
    )
    await dialog.getByRole('button', { name: /^change plan$/i }).click()
    const response = await applied

    expect(response.status()).toBeLessThan(300)
    await expect(page.getByText(/your plan has changed/i)).toBeVisible()
    await expect(page.getByText(/correct the highlighted fields/i)).toHaveCount(0)
  })
})

test.describe('rebuilding a virtual machine', () => {
  /*
   * Last of the machine specs on purpose: a rebuild replaces the disk and
   * leaves a terminal job behind, and the plan-change spec above needs the
   * same service quiet to be quoted.
   */
  test('a reinstall is confirmed with the hostname and carried out', async ({ page }) => {
    await openMachine(page, fixtures.operableHostname)
    await openSection(page, /^danger zone$/i)

    await page.getByRole('button', { name: /^reinstall$/i }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(fixtures.operableHostname)

    const accepted = page.waitForResponse(
      (response) => response.url().includes('/reinstall') && response.status() === 202,
    )
    await dialog.getByRole('button', { name: /reinstall this server/i }).click()
    await accepted
    await expect(dialog).toBeHidden()

    /*
     * The queue runs inline against the fake, so the rebuild has already
     * finished by the time the machine refetches. Where it got to is reported
     * on the overview, beside when it was asked for — the danger zone offers
     * the act, the overview records it.
     */
    await openSection(page, /^overview$/i)
    await expect(page.getByText(/^rebuilt$/i)).toBeVisible()
    await expect(page.getByText(/correct the highlighted fields/i)).toHaveCount(0)
  })
})

test.describe('two-factor authentication', () => {
  // Whatever happened above, the seeded customer signs in with a password
  // alone for the rest of the suite.
  test.afterEach(() => { resetTwoFactor() })

  test('is turned on with the current password, refuses a wrong one, and can be turned off again', async ({
    page,
  }) => {
    await page.goto('/security')

    await page.getByRole('button', { name: /turn on two-factor/i }).click()

    // Nothing has been sent yet; the password step stands in the way.
    const password = page.getByLabel(/current password/i).first()
    await password.fill('not-the-password')
    await page.getByRole('button', { name: /^continue$/i }).click()
    await expect(page.getByText(/that password is incorrect/i)).toBeVisible()

    await password.fill(users.customer.password)
    await page.getByRole('button', { name: /^continue$/i }).click()

    const secret = (await page.locator('code').first().innerText()).trim()
    expect(secret).toMatch(/^[A-Z2-7]{16,}$/)

    await page.getByLabel(/authentication code/i).fill(totp(secret))
    await page.getByRole('button', { name: /^confirm$/i }).click()

    await expect(page.getByText(/save these recovery codes now/i)).toBeVisible()

    await page.reload()
    await expect(page.getByText(/asks for a code from your authenticator/i)).toBeVisible()

    // And off again, with the password, so the rest of the suite can sign in.
    await page.getByRole('button', { name: /turn off two-factor/i }).click()
    await page.getByLabel(/current password/i).first().fill(users.customer.password)
    await page.getByRole('button', { name: /turn off two-factor/i }).last().click()
    await expect(page.getByRole('button', { name: /turn on two-factor/i })).toBeVisible()
  })
})

test.describe('revoking access', () => {
  test('revoking an API token asks first, names the token, and revokes it once confirmed', async ({ page }) => {
    await page.goto('/api-tokens')

    await page.getByLabel(/^name$/i).fill('wave-0-token')
    await page.getByLabel(/current password/i).fill(users.customer.password)
    await page.getByRole('button', { name: /create token/i }).click()
    await expect(page.getByText(/only time it is shown/i)).toBeVisible()

    const row = page.getByRole('row').filter({ hasText: 'wave-0-token' })
    await row.getByRole('button', { name: /^revoke$/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/wave-0-token/)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(row.getByRole('button', { name: /^revoke$/i })).toBeVisible()

    await row.getByRole('button', { name: /^revoke$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^revoke token$/i }).click()

    await expect(row.getByText(/^revoked$/i)).toBeVisible()
    await expect(row.getByRole('button', { name: /^revoke$/i })).toHaveCount(0)
  })

  test('signing out another device asks first, and so does signing out every other device', async ({
    page,
    browser,
  }) => {
    /*
     * A known number of devices to start from.
     *
     * The security screen lists a hundred sessions at most, and one browser
     * running the whole suite signs in far more often than that: by the time
     * this spec runs, revoking a row only lets an older session slide into
     * view and the count never moves. So every stored session for the account
     * is dropped and this page signs in again as the only one, which makes
     * the numbers below the ones the platform decides rather than the ones
     * the harness happened to leave behind.
     */
    forgetSessions()
    await signIn(page)

    // A second signed-in device, so there is another session to end.
    const other = await browser.newContext()
    const otherPage = await other.newPage()
    await signIn(otherPage)

    await page.goto('/security')

    const rows = page.getByRole('row').filter({ has: page.getByRole('button', { name: /^sign out$/i }) })
    await expect(rows.first()).toBeVisible()
    const before = await rows.count()

    await rows.first().getByRole('button', { name: /^sign out$/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/has to sign in again/i)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(rows).toHaveCount(before)

    await rows.first().getByRole('button', { name: /^sign out$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /sign out that device/i }).click()
    await expect(rows).toHaveCount(before - 1)

    // Another device, then all of them at once.
    const third = await browser.newContext()
    await signIn(await third.newPage())
    await page.reload()
    await expect(rows.first()).toBeVisible()

    await page.getByRole('button', { name: /sign out other devices/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /sign out other devices/i }).click()
    await expect(rows).toHaveCount(0)
    await expect(page.getByText(/^this device$/i)).toBeVisible()

    await other.close()
    await third.close()
  })
})

test.describe('withdrawing a request', () => {
  test('withdrawing a country change asks first and closes the request once confirmed', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('button', { name: /request a change/i }).click()
    await page.getByLabel(/country \(two letters\)/i).fill('AE')
    await page.getByLabel(/^why$/i).fill('Wave 0 withdrawal spec.')
    await page.getByRole('button', { name: /send the request/i }).click()

    const change = page.getByTestId('country-currency-change').first()
    await expect(change).toBeVisible()

    await change.getByRole('button', { name: /^withdraw$/i }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/nothing on the account changes/i)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(change.getByRole('button', { name: /^withdraw$/i })).toBeVisible()

    await change.getByRole('button', { name: /^withdraw$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^withdraw request$/i }).click()

    // The open request is gone and the form is offered again.
    await expect(page.getByRole('button', { name: /request a change/i })).toBeVisible()
    await expect(page.getByText(/withdrawn/i).first()).toBeVisible()
  })
})

test.describe('what is for sale', () => {
  test('only what the readiness engine permits is offered, and a prepared product is not reachable by link', async ({
    page,
  }) => {
    await page.goto('/catalogue')

    await expect(page.getByRole('link', { name: /view plans/i })).toHaveCount(3)
    for (const prepared of [/\bcdn\b/i, /object storage/i, /gpu/i, /email hosting/i, /kubernetes/i]) {
      await expect(page.getByText(prepared)).toHaveCount(0)
    }

    // A prepared product has no catalogue row at all, so its would-be link is
    // a not-found with nothing to buy on it.
    await page.goto('/catalogue/cdn')
    await expect(page.getByRole('alert')).toContainText(/does not exist/i)
    await expect(page.getByRole('button', { name: /place order/i })).toHaveCount(0)
  })
})
