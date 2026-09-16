import { expect, test, type Locator, type Page } from '@playwright/test'

import { pageOverflow } from '../support/layout'
import { fixtures, signIn, users } from '../support/helpers'

/*
 * §10. The dialogues, on a narrow phone.
 *
 * A confirmation dialogue is the worst screen in the portal to get wrong,
 * because it is the last thing between a customer and something they cannot
 * undo. If the confirm button is off the edge, or the typed-phrase box is
 * unreachable, or the body has to be scrolled sideways to read what is about
 * to be lost, then the customer either cannot act or acts without reading.
 *
 * Everything here is measured on a real dialogue opened through the product's
 * own controls, at 393 and at 360.
 */

test.describe('a dialogue on a narrow screen', () => {
  /*
   * Two minutes, not ten. These are short journeys, and a generous timeout
   * only means that a locator naming a control that does not exist waits for
   * the whole allowance before saying so — which is ten minutes of a suite run
   * spent proving a typo. It cost exactly that once.
   */
  test.describe.configure({ timeout: 120_000 })

  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('fits the viewport when it asks for a typed hostname', async ({ page }) => {
    await page.goto('/vps')
    await page.locator('main').getByRole('link', { name: fixtures.operableHostname, exact: true }).click()
    const base = new URL(page.url()).pathname
    await page.goto(`${base}/danger`)

    await page.getByRole('button', { name: /reinstall/i }).first().click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    await expectUsable(page, dialog, 'the reinstall confirmation')

    /*
     * The phrase box is the whole mechanism of this dialogue, so it is not
     * enough for it to exist: it has to be reachable and typeable at this
     * width. Typed rather than merely asserted visible, because a box that is
     * present but zero pixels wide passes the weaker check.
     */
    const phrase = dialog.locator('input[type="text"], input:not([type])').first()
    await phrase.fill(fixtures.operableHostname)
    await expect(phrase).toHaveValue(fixtures.operableHostname)

    const confirm = dialog.getByRole('button', { name: /reinstall/i }).last()
    await expect(confirm).toBeEnabled()
    await expectInside(page, confirm, 'the confirm button')

    // Left as it was found: this spec proves the dialogue is usable, and does
    // not rebuild a machine other specs depend on.
    await dialog.getByRole('button', { name: /^cancel$/i }).click()
    await expect(dialog).toBeHidden()
  })

  test('fits the viewport when it revokes a credential with a long name', async ({ page }) => {
    /*
     * This spec mints its own token rather than reaching for one the seeder
     * made, because the seeded account has none — and because §11 wants a
     * realistically long name rather than a short fixture. "Build server —
     * staging deploy pipeline (eu-west)" is the kind of thing somebody
     * actually types when they are naming the fourth token this quarter, and
     * it is longer than the column it lands in.
     */
    const name = 'Build server — staging deploy pipeline (eu-west)'

    await page.goto('/api-tokens')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await page.getByLabel(/^name$/i).fill(name)
    await page.getByLabel(/current password/i).fill(users.customer.password)
    await page.getByRole('button', { name: /^create token$/i }).click()

    // That the secret is shown at all, and that it fits, is asserted in
    // narrow/technical-values.e2e.ts — it is a long-value question, not a
    // dialogue one.
    await expect(page.locator('code')).toBeVisible()

    const row = page.getByRole('row').filter({ hasText: 'Build server' })
    const revoke = row.getByRole('button', { name: /^revoke$/i })
    await revoke.scrollIntoViewIfNeeded()
    await revoke.click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The name is quoted back in the title, so a long name is a long title.
    await expect(dialog).toContainText('Build server')
    await expectUsable(page, dialog, 'the token revocation')

    /*
     * Revoked rather than cancelled, so the credential this spec minted cannot
     * outlive it: the narrow, phone and Arabic projects share one database.
     *
     * The row stays, and that is the product being right rather than the test
     * being wrong — a customer auditing their own credentials needs to see the
     * one they revoked and when. So the assertion is on the state, not on the
     * row's absence.
     */
    await dialog.getByRole('button', { name: /^revoke token$/i }).click()
    await expect(dialog).toBeHidden()
    await expect(row).toContainText(/^Revoked$|Revoked/)
  })

  test('fits the viewport when it changes what a colleague can do', async ({ page }) => {
    await page.goto('/settings/team')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    /*
     * Choosing a role opens the confirmation rather than sending it, so the
     * dialogue is reached through the row's own select — there is no "change
     * role" button to press. The select keeps the member's current role until
     * the server agrees, which is what makes cancelling safe here.
     */
    const row = page.getByRole('row').filter({ hasText: fixtures.teammateEmail })
    await expect(row).toHaveCount(1)
    await row.getByRole('combobox').selectOption('billing')

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await expectUsable(page, dialog, 'the role change')

    /*
     * §10 asks about danger emphasis. Changing a role is reversible and takes
     * effect on the colleague's next request, so this dialogue is deliberately
     * *not* painted the same red as "destroy this machine" — the ConfirmDialog
     * docblock says exactly that. Asserted here so that a later restyle cannot
     * quietly make every confirmation look equally final, which would teach a
     * customer that the red means nothing.
     */
    const confirm = dialog.getByRole('button').last()
    const fill = await confirm.evaluate((element) => getComputedStyle(element).backgroundColor)

    expect(fill, 'a reversible change must not be painted as a destructive one').not.toBe(
      await destructiveFill(page),
    )

    await dialog.getByRole('button', { name: /^cancel$/i }).click()
    await expect(dialog).toBeHidden()
  })

  test('shows the drawer without covering the way out of it', async ({ page }) => {
    await page.getByRole('button', { name: /^menu$/i }).click()
    const drawer = page.getByRole('dialog', { name: /^menu$/i })
    await expect(drawer).toBeVisible()

    await expectInside(page, drawer, 'the navigation drawer')
    await expectInside(page, drawer.getByRole('button', { name: /^close$/i }), 'the drawer close button')
    await expectInside(page, drawer.getByRole('button', { name: /sign out/i }), 'sign out in the drawer')

    // The drawer is a modal dialogue and must not leave the page behind it
    // scrollable sideways either.
    expect(await pageOverflow(page), 'the page behind the drawer').toBeLessThanOrEqual(1)
  })
})

/** No wider than the viewport, no sideways scrolling, and a way out. */
async function expectUsable(page: Page, dialog: Locator, what: string): Promise<void> {
  const width = page.viewportSize()?.width ?? 0

  const box = await dialog.boundingBox()
  expect(box, `${what} has no box`).not.toBeNull()
  expect(
    Math.round(box?.width ?? 0),
    `${what} is wider than the ${width.toString()}px viewport`,
  ).toBeLessThanOrEqual(width)

  const sideways = await dialog.evaluate((element) => element.scrollWidth - element.clientWidth)
  expect(sideways, `${what} has to be scrolled sideways to read`).toBeLessThanOrEqual(1)

  /*
   * Taller than the viewport is allowed — a dialogue that lists what will be
   * lost can be long — but only if it scrolls. Clipped is not allowed, because
   * the confirm button is at the bottom.
   */
  const taller = await dialog.evaluate((element) => ({
    content: element.scrollHeight,
    visible: element.clientHeight,
    scrolls: getComputedStyle(element).overflowY !== 'visible',
  }))

  if (taller.content > taller.visible + 1) {
    expect(taller.scrolls, `${what} is taller than the screen and does not scroll`).toBe(true)
  }

  await expectInside(page, dialog.getByRole('button', { name: /^cancel$|^keep/i }), `the way out of ${what}`)
}

/** Visible, and with both edges inside the viewport. */
async function expectInside(page: Page, locator: Locator, what: string): Promise<void> {
  await expect(locator, `${what} is not visible`).toBeVisible()

  const width = page.viewportSize()?.width ?? 0
  const box = await locator.boundingBox()

  expect(Math.round(box?.x ?? -1), `${what} starts off the screen`).toBeGreaterThanOrEqual(0)
  expect(
    Math.round((box?.x ?? 0) + (box?.width ?? 0)),
    `${what} ends past the ${width.toString()}px edge`,
  ).toBeLessThanOrEqual(width)
}

/**
 * What a destructive control is actually filled with, read from the design
 * system rather than written down here — so the comparison cannot go stale
 * when the token changes.
 */
async function destructiveFill(page: Page): Promise<string> {
  return page.evaluate(() => {
    const probe = document.createElement('div')
    probe.style.backgroundColor = 'var(--danger-surface)'
    document.body.append(probe)
    const fill = getComputedStyle(probe).backgroundColor
    probe.remove()

    return fill
  })
}
