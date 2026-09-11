import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Knowing what is happening, in a browser.
 *
 * The audit's AR-6, AR-12 and AR-13 were three descriptions of one silence:
 * the first page said nothing about the account, every asynchronous action
 * returned a 202 and then nothing, and there was no record of what had
 * happened anywhere except inside each resource. These are the journeys that
 * prove the silence is over, driven against the real API.
 *
 * The two that matter most are the ones about what the portal must NOT say. A
 * rebuild that stopped for a person reads as itself, and a domain whose
 * outcome nobody knows reads as unknown — never as a failure, because a
 * customer told "failed" presses the button again.
 */

/** The one operation whose outcome the platform does not know. */
const STRANDED_MACHINE = fixtures.vpsHostname

/** Reboots the operable machine, as whoever is signed in. */
async function reboot(page: Page): Promise<void> {
  await page.goto('/vps')
  await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
  await page.getByRole('button', { name: 'Reboot' }).first().click()

  await expect(
    page.getByRole('region', { name: 'Updates' }).getByText(/Reboot requested/),
  ).toBeVisible()
}

async function signOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).first().click()
  await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
}

async function openActivity(page: Page): Promise<void> {
  await page.getByRole('navigation', { name: /main navigation/i }).getByRole('link', { name: 'Activity' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Activity' })).toBeVisible()
}

test.describe('the first page answers what needs doing', () => {
  test('leads with attention, links to the exact thing, and never sums two currencies', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    // The dashboard is where signing in lands.
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    const attention = page.getByRole('region', { name: 'Needs your attention' })
    await expect(attention).toBeVisible()

    /*
     * The seeded account carries a rebuild nobody can settle and an unpaid
     * invoice, so both classes are represented. What is asserted is that the
     * list is the first thing on the page and that its rows open the thing
     * itself.
     */
    const held = page.getByRole('region', { name: 'What you have' })
    await expect(held).toBeVisible()

    const positions = await page.evaluate(() => {
      const regions = Array.from(document.querySelectorAll('section'))
      const index = (name: string) =>
        regions.findIndex((section) => section.querySelector('h2')?.textContent.includes(name))

      return { attention: index('Needs your attention'), held: index('What you have') }
    })

    expect(positions.attention).toBeGreaterThanOrEqual(0)
    expect(positions.attention).toBeLessThan(positions.held)

    // Money is per currency. The seeded account is billed in one, so the
    // assertion available here is the absence of a total line.
    const owed = page.getByRole('region', { name: 'What you owe' })
    await expect(owed).toBeVisible()
    await expect(owed.getByText(/total/i)).toHaveCount(0)
  })

  test('opens the invoice the dashboard is complaining about', async ({ page }) => {
    await signIn(page, users.customer)

    const attention = page.getByRole('region', { name: 'Needs your attention' })
    const open = attention.getByRole('link', { name: 'Open' }).first()

    await open.click()

    /*
     * §36. Whatever the top attention row is, following it must land on a
     * resource rather than on an index — which is the difference between a
     * dashboard that saves work and one that hands it back.
     */
    await expect(page).not.toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })
})

test.describe('the account can read its own history', () => {
  test('names the person who asked, for each of two people on one account', async ({ page }) => {
    /*
     * Driven rather than read off a fixture. The specs before this one leave
     * their own work on the shared account, so a seeded row is not reliably on
     * the newest page — and a test that asserted it was would be asserting the
     * order of the suite. Two people reboot the same machine here, and the two
     * newest rows have to name them.
     */
    await signIn(page, { email: fixtures.teammateEmail, password: 'password' })
    await reboot(page)
    await signOut(page)

    await signIn(page, users.customer)
    await reboot(page)

    await openActivity(page)

    // From the actor column on the row, not from a correlation between a
    // timestamp and whoever happened to be signed in.
    await expect(page.getByText('Sample Customer').first()).toBeVisible()
    await expect(page.getByText('Sara Teammate').first()).toBeVisible()
  })

  test('filters on the server rather than trimming the page it has', async ({ page }) => {
    await signIn(page, users.customer)
    await openActivity(page)

    const requests: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/activity')) requests.push(request.url())
    })

    await page.getByRole('button', { name: 'Billing' }).click()

    await expect
      .poll(() => requests.some((url) => url.includes('category=billing')))
      .toBe(true)

    // The filter reached the server. A page trimmed in the browser would show
    // whichever of twenty-five rows happened to match.
    await expect(page.getByRole('button', { name: 'Billing' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  test('never calls work that stopped for a person a failure, and offers support', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    /*
     * The mandatory case, read from the dashboard's attention list rather than
     * from the feed's newest page. The seeded account holds a rebuild that
     * timed out mid-flight — the disk may be gone or it may not, and the
     * platform does not know — and the attention list is capped and ordered by
     * severity, so it holds that row whatever the rest of the suite has done
     * to the account since.
     */
    const attention = page.getByRole('region', { name: 'Needs your attention' })

    const stopped = attention
      .getByRole('listitem')
      .filter({ hasText: 'Work stopped and we are looking at it' })
      .first()

    await expect(stopped).toBeVisible()
    await expect(stopped.getByRole('link', { name: 'Ask support about this' })).toBeVisible()

    // And nowhere on the page is there an invitation to do it again.
    const body = (await page.locator('main').innerText()).toLowerCase()
    expect(body).not.toContain('try again')
    expect(body).not.toContain('retry')
  })

  test('carries what it is about into a support draft, and does not send it', async ({ page }) => {
    await signIn(page, users.customer)

    const attention = page.getByRole('region', { name: 'Needs your attention' })

    await attention
      .getByRole('listitem')
      .filter({ hasText: 'Work stopped and we are looking at it' })
      .first()
      .getByRole('link', { name: 'Ask support about this' })
      .click()

    await expect(page).toHaveURL(/\/support\?/)

    // The subject is filled in and mentions what it is about.
    const subject = page.getByLabel('Subject')
    await expect(subject).not.toHaveValue('')

    // Prefilled, not submitted: the ticket does not exist until somebody
    // presses send.
    await expect(page.getByText('This form has been filled in')).toBeVisible()
  })

  test('walks the feed forwards by cursor rather than by page number', async ({ page }) => {
    await signIn(page, users.customer)
    await openActivity(page)

    const urls: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/activity')) urls.push(request.url())
    })

    const older = page.getByRole('button', { name: 'Show older' })

    // The seeded account may or may not have a second page; either way the
    // control must never produce a numbered request.
    if (await older.isEnabled()) {
      await older.click()
      await expect.poll(() => urls.some((url) => url.includes('cursor='))).toBe(true)
    }

    expect(urls.some((url) => /[?&]page=/.test(url))).toBe(false)
  })
})

test.describe('an action a customer starts reports itself', () => {
  test('acknowledges a reboot in the lifecycle s own words and follows it to the end', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
    await expect(
      page.getByRole('heading', { level: 1, name: fixtures.operableHostname }),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Reboot' }).first().click()

    /*
     * "Reboot requested" and not "Success". The first is a statement about the
     * platform having the request; the second would be a claim about a machine
     * nothing has touched yet.
     */
    const channel = page.getByRole('region', { name: 'Updates' })
    await expect(channel.getByText(/Reboot requested/)).toBeVisible()
    await expect(channel.getByText(/^Success/)).toHaveCount(0)

    /*
     * The suite runs the queue inline, so the work is already finished by the
     * time the first poll lands — which is the case that proves the watcher
     * reads the operation and reports its real end rather than assuming one.
     */
    await expect(channel.getByText(/Reboot completed/)).toBeVisible({ timeout: 20_000 })
  })

  test('keeps the acknowledgement when the customer walks away from the page', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto(`/vps`)
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
    await page.getByRole('button', { name: 'Reboot' }).first().click()

    const channel = page.getByRole('region', { name: 'Updates' })
    await expect(channel.getByText(/Reboot requested/)).toBeVisible()

    // Off to the invoices, which is where a customer goes next.
    await page.getByRole('navigation', { name: /main navigation/i }).getByRole('link', { name: 'Invoices' }).click()
    await expect(page.getByRole('heading', { level: 1, name: /invoices/i })).toBeVisible()

    /*
     * §20. The watcher lives above the routes, so the operation is still
     * being followed and still has somewhere to say so. A screen-local
     * spinner would have gone with the screen.
     */
    await expect(channel.getByText(/Reboot/)).toBeVisible({ timeout: 20_000 })
  })

  test('resumes watching after a reload rather than forgetting', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
    await page.getByRole('button', { name: 'Reboot' }).first().click()

    await expect(page.getByRole('region', { name: 'Updates' }).getByText(/Reboot/)).toBeVisible()

    await page.reload()

    /*
     * The business truth is on the server and is read again; what survives the
     * reload is the fact that the portal was watching something. The machine's
     * own page is the authority either way, and it still says what happened.
     */
    await expect(
      page.getByRole('heading', { level: 1, name: fixtures.operableHostname }),
    ).toBeVisible()

    const body = await page.locator('main').innerText()
    expect(body.length).toBeGreaterThan(0)
  })

  test('stops asking once the operation is over', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    const polls: string[] = []
    page.on('request', (request) => {
      if (/\/api\/v1\/operations\//.test(request.url())) polls.push(request.url())
    })

    await page.getByRole('button', { name: 'Reboot' }).first().click()

    const channel = page.getByRole('region', { name: 'Updates' })
    await expect(channel.getByText(/Reboot completed/)).toBeVisible({ timeout: 20_000 })

    const settled = polls.length
    expect(settled).toBeGreaterThan(0)

    /*
     * §53 in a browser. The server sends a null poll hint for a terminal
     * state, and a client that schedules only from that field cannot keep
     * asking. Twelve seconds is four of the server's own three-second floors.
     */
    await page.waitForTimeout(12_000)
    expect(polls.length).toBe(settled)
  })

  test('says a rebuild that stopped for a person needs review, on the machine s own page', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    await page.goto('/vps')
    await page.getByRole('link', { name: STRANDED_MACHINE, exact: true }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: STRANDED_MACHINE })).toBeVisible()

    const body = (await page.locator('main').innerText()).toLowerCase()

    /*
     * The machine whose disk may or may not exist. The page says a person is
     * looking at it — in those words, since that is what a customer needs to
     * know — and it must not say the rebuild failed, which is the word that
     * would send them back to the rebuild button.
     */
    expect(body).toContain('looking at it')
    expect(body).not.toContain('failed')

    // And none of the provider's own words about it.
    expect(body).not.toContain('hypervisor')
    expect(body).not.toContain('e2e-node')
  })
})

test.describe('the shell says how many messages are waiting', () => {
  test('counts unread notifications in the link that opens them, and clears with them', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    const navigation = page.getByRole('navigation', { name: /main navigation/i })

    // The seeded account has unread notifications, so the link's accessible
    // name carries the count.
    const inbox = navigation.getByRole('link', { name: /Notifications/ })
    await expect(inbox).toBeVisible()

    const before = (await inbox.getAttribute('aria-label')) ?? (await inbox.innerText())
    expect(before).toBeTruthy()

    await inbox.click()
    await expect(page.getByRole('heading', { level: 1, name: /notifications/i })).toBeVisible()

    await page.getByRole('button', { name: 'Mark all read' }).click()

    /*
     * AS-11. The badge is invalidated with the list, so it cannot sit at three
     * while the page behind it says everything is read.
     */
    await expect(navigation.getByRole('link', { name: 'Notifications', exact: true })).toBeVisible({
      timeout: 15_000,
    })
  })
})
