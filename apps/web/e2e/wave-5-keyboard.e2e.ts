import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Wave 5, W5.6. The whole portal, driven with no mouse.
 *
 * This is where the defects are. axe checks what a machine can check — a
 * control with no name, a contrast ratio, a misplaced ARIA attribute — and
 * every one of those is worth catching. What it cannot tell you is whether the
 * tab order matches the reading order, whether the skip link actually lands
 * anywhere, whether focus comes back from a dialogue, or whether closing
 * something leaves the customer's cursor on an element that no longer exists.
 *
 * So this file never calls `.click()`. Every interaction is `Tab`, `Enter`,
 * `Space`, `Escape` or an arrow key, which is the only way to find out.
 */

/** The accessible name and role of whatever currently has focus. */
async function focused(
  page: Page,
): Promise<{ role: string; name: string; tag: string; inNavigation: boolean }> {
  return page.evaluate(() => {
    const element = document.activeElement

    if (element === null) return { role: 'none', name: '', tag: 'none', inNavigation: false }

    /*
     * `textContent` is a stand-in for the accessible name and not the same
     * thing: it includes `aria-hidden` subtrees, which the name computation
     * excludes. This is good enough to *navigate* by, and it is deliberately
     * not what the naming assertions use — a first draft of this file read a
     * name of "Notifications1, 1 unread" off here and nearly had me "fix" a
     * badge that was already correct. Names are asserted through
     * `getByRole(..., { name })`, which uses the browser's own computation.
     */
    const describedBy = element.getAttribute('aria-labelledby')

    const label =
      element.getAttribute('aria-label') ??
      (describedBy === null
        ? element.textContent
        : (document.getElementById(describedBy)?.textContent ?? ''))

    /*
     * Whether focus is inside the main navigation landmark, which is how the
     * walk below tells a destination from a control that happens to share its
     * word. The portal has several navigation landmarks — the sidebar, a
     * resource's sections, the breadcrumb trail — and only one of them is
     * named "Main navigation".
     */
    const nav = element.closest('nav')
    const landmark = (nav?.getAttribute('aria-label') ?? '').toLowerCase()

    return {
      role: element.getAttribute('role') ?? element.tagName.toLowerCase(),
      name: label.trim().slice(0, 80),
      tag: element.tagName.toLowerCase(),
      inNavigation: landmark.includes('main navigation'),
    }
  })
}

/**
 * Tabs until the accessible name matches, and says so if it never does.
 *
 * `inNavigation` narrows the match to the sidebar, and it is not a
 * convenience: the activity feed carries filters named after the same things
 * the destinations are — "Support" is both a place and a category — so an
 * unqualified walk found the filter first, applied it, and stayed on the page
 * it was already on. The failure looked like a navigation that did nothing.
 */
async function tabTo(
  page: Page,
  pattern: RegExp,
  options: { inNavigation?: boolean; limit?: number } = {},
): Promise<void> {
  const limit = options.limit ?? 140

  for (let step = 0; step < limit; step += 1) {
    await page.keyboard.press('Tab')

    const where = await focused(page)

    if (options.inNavigation === true && ! where.inNavigation) continue

    if (pattern.test(where.name)) return
  }

  const where = await focused(page)

  throw new Error(
    `Tabbed ${String(limit)} times without reaching ${String(pattern)}. Focus ended on ` +
      `${where.tag}[role=${where.role}] "${where.name}".`,
  )
}

test.describe('the whole portal, with no mouse', () => {
  test('signs in, works, and signs out using only the keyboard', async ({ page }) => {
    /*
     * Signing in is the one step that uses the helper, because it fills two
     * fields by selector and the journey under test starts once there is a
     * session. Everything after this line is keys.
     */
    await signIn(page, users.customer)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    /*
     * --- arriving somewhere puts focus on the page ------------------------
     *
     * Signing in is a navigation, and `useRouteFocus` moves focus to the new
     * page's heading on every one of them. That is what makes the page
     * announce itself to a screen reader — nothing else does, because the
     * document never reloaded — and it is why the next Tab continues from the
     * content instead of from the eleventh destination in the sidebar.
     */
    const arrived = await focused(page)
    expect(arrived.tag, 'a navigation leaves focus on the new page heading').toBe('h1')

    /*
     * --- the skip link is the first stop of a fresh load ------------------
     *
     * Reloaded on purpose. A full load is the one arrival where focus is
     * deliberately left alone — the document has just been handed over at the
     * top — so it is the case where the skip link has to be the first stop,
     * and it is the walk a customer takes when they open the portal.
     */
    await page.reload()
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await page.keyboard.press('Tab')

    const first = await focused(page)
    expect(first.name, 'the first tab stop is the skip link').toMatch(/skip to main content/i)

    await page.keyboard.press('Enter')

    const landed = await page.evaluate(() => document.activeElement?.id ?? '')
    expect(landed, 'the skip link moves focus to <main>').toBe('main-content')

    // --- the sidebar, by keyboard ------------------------------------------
    await tabTo(page, /^Cloud VPS$/i, { inNavigation: true })
    await page.keyboard.press('Enter')

    await expect(page.getByRole('heading', { level: 1, name: /cloud vps/i })).toBeVisible()

    // --- into a machine ----------------------------------------------------
    await tabTo(page, new RegExp(fixtures.operableHostname, 'i'))
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    /*
     * The action first, then a section. The other order tabs out of a
     * focus-heavy section page and has to wrap the whole document to get back
     * to the machine's controls, which is a fact about this journey's shape
     * rather than a defect — but it makes the test measure patience instead of
     * accessibility.
     *
     * Force off rather than Reboot: only the destructive power actions carry a
     * confirmation. A reboot is reversible and sends on the press, which is
     * the graded-confirmation policy working, and a test that expected a
     * dialogue there would have been asserting the wrong product.
     */
    await tabTo(page, /^Force off$/i)

    const opener = await focused(page)
    expect(opener.name).toMatch(/force off/i)

    await page.keyboard.press('Enter')

    // --- the confirmation takes focus --------------------------------------
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    const inside = await page.evaluate(() => {
      const open = document.querySelector('dialog[open]')

      return open !== null && open.contains(document.activeElement)
    })
    expect(inside, 'focus is inside the dialog once it opens').toBe(true)

    // --- Escape closes it, and nothing was sent ----------------------------
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()

    // --- and focus came back to the control that opened it -----------------
    const returned = await focused(page)
    expect(returned.name, 'focus returns to the opener').toMatch(/force off/i)

    // --- a resource section, which is a list of links rather than tabs -----
    await tabTo(page, /^Backups$/i)
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/backups$/)

    // --- the rest of the journey ------------------------------------------
    for (const [destination, heading] of [
      ['Dashboard', /./],
      ['Activity', /activity/i],
      ['Support', /support|help/i],
      ['Profile', /profile|account/i],
    ] as const) {
      await tabTo(page, new RegExp(`^${destination}$`, 'i'), { inNavigation: true })
      await page.keyboard.press('Enter')
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)
    }

    // --- sign out ---------------------------------------------------------
    /*
     * Unqualified: the sign-out button sits in the sidebar's footer, below the
     * navigation landmark rather than inside it — it is a control, not a
     * destination, and the landmark lists places to go.
     */
    await tabTo(page, /^Sign out$/i)
    await page.keyboard.press('Enter')

    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
  })
})

test.describe('a confirmation dialogue, by keyboard', () => {
  test('cancelling sends nothing and gives focus back', async ({ page }) => {
    /*
     * The W5.5 rule seen from the accessibility side: a dialogue dismissed by
     * Escape must be as inert as one dismissed by pressing Cancel. If Escape
     * reached the form behind it, or if the dialog's own default submission
     * fired, this would be the quietest possible way to reboot a machine.
     */
    let powerRequests = 0

    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes('/power')) powerRequests += 1
    })

    await signIn(page, users.customer)
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    await tabTo(page, /^Force off$/i)
    await page.keyboard.press('Enter')
    await expect(page.getByRole('dialog')).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()

    expect(powerRequests, 'Escape must not send the mutation').toBe(0)

    const returned = await focused(page)
    expect(returned.name).toMatch(/force off/i)
  })

  test('a typed-confirmation dialogue still demands the phrase', async ({ page }) => {
    /*
     * Accessibility work must not quietly lower a deliberate barrier. The
     * reinstall dialogue asks for the machine's hostname because a reinstall
     * cannot be undone, and reaching it by keyboard must not skip that.
     */
    await signIn(page, users.customer)
    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    // Reinstall is in the danger zone, which is a section of its own.
    await tabTo(page, /^Danger zone$/i)
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/danger$/)

    await tabTo(page, /^Reinstall$/i)
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The confirm button is unusable until the phrase is typed.
    const confirm = dialog.getByRole('button', { name: /reinstall/i }).last()
    await expect(confirm).toBeDisabled()

    await page.keyboard.press('Escape')
  })
})

test.describe('focus after a route change', () => {
  test('does not leave the customer on an element that has gone', async ({ page }) => {
    /*
     * The defect this looks for is specific: activate a link in the sidebar,
     * the route changes, and focus is left on a DOM node the new page has
     * replaced — so the next Tab starts from the document root and the
     * customer has silently lost their place.
     *
     * The strategy that answers it is `useRouteFocus`: on a change of
     * *pathname*, focus moves to the new page's heading. An earlier draft of
     * this test asserted the opposite — that the sidebar link keeps focus —
     * on the reasoning that moving focus "announces the page title every time
     * anybody clicks anything". That conflated a navigation with a render. A
     * render is not a navigation, the hook does not fire on one, and a query
     * string changing is not one either; what is left is exactly the event a
     * customer needs told about, because the document did not reload and
     * nothing else tells them.
     *
     * Leaving focus on the link has the cost the sidebar was built to avoid:
     * the next Tab continues through the remaining destinations rather than
     * entering the page just opened.
     */
    await signIn(page, users.customer)

    await tabTo(page, /^Activity$/i, { inNavigation: true })

    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 1, name: /activity/i })).toBeVisible()

    const after = await focused(page)

    // On the new page's heading: named, real, and the thing a screen reader
    // reads out as the page it has arrived at.
    expect(after.tag).toBe('h1')
    expect(after.name).toMatch(/activity/i)
    expect(after.inNavigation, 'focus left the navigation column').toBe(false)

    const connected = await page.evaluate(() => document.activeElement?.isConnected ?? false)
    expect(connected, 'the focused element is still in the document').toBe(true)

    /*
     * And not a tab stop of its own: focus can be *sent* to the heading
     * without everybody having to walk past it on the way into the page.
     */
    expect(
      await page.getByRole('heading', { level: 1 }).getAttribute('tabindex'),
      'the heading is focusable but not in the tab order',
    ).toBe('-1')
  })
})
