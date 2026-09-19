import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * One place per resource, in a browser.
 *
 * The audit's AR-7: a customer could not answer "what is this, is it healthy,
 * what does it cost, what can I do to it" about anything they owned without
 * visiting four screens and joining them by eye. Wave 3 gave every family a
 * page per thing, so what this file drives is exactly that question, once per
 * family, and the two properties the page shape has to have: the sections are
 * addresses, and nothing operational is on them.
 *
 * These are real journeys against the real API. Where a spec changes a
 * fixture — the auto-renew switch — it puts it back.
 */

/** Opens one resource from its index, by the name a customer recognises. */
async function open(page: Page, index: string, identity: string): Promise<void> {
  await page.goto(index)
  await page.getByRole('link', { name: identity, exact: true }).first().click()
  await expect(page.getByRole('heading', { level: 1, name: identity })).toBeVisible()
}

/** Moves to one section. They are links, so each one is an address. */
async function section(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name }).click()
}

/**
 * Nothing on the page names the estate.
 *
 * The customer-safe model, checked against the rendered document rather than
 * against a resource test: a page assembles several documents, and the one
 * that leaks is the one nobody thought to check.
 */
async function nothingOperational(page: Page): Promise<void> {
  const body = (await page.locator('body').innerText()).toLowerCase()

  for (const forbidden of [
    'proxmox',
    'cluster',
    'datastore',
    'bmc',
    'ilo',
    'idrac',
    'rack',
    'credentials_reference',
    'provider_reference',
    'hypervisor',
    'ansible',
    'terraform',
  ]) {
    expect(body, `the page names "${forbidden}"`).not.toContain(forbidden)
  }
}

/** The page never scrolls sideways, at whatever width it is being read. */
async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )

  // One pixel of slack for sub-pixel layout rounding.
  expect(overflow).toBeLessThanOrEqual(1)
}

test.describe('a machine of its own', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('answers what it is, whether it is healthy, and what it costs, in one place', async ({
    page,
  }) => {
    await open(page, '/vps', fixtures.operableHostname)

    // What it is, and where it sits, in words rather than in identifiers.
    const trail = page.getByRole('navigation', { name: /you are here/i })
    await expect(trail).toContainText(fixtures.operableHostname)
    await expect(trail).not.toContainText('01J')

    // Healthy: the power state and the service state, from the API.
    await expect(page.getByText(/^running$/i).first()).toBeVisible()

    // What it is made of, and the address it answers on. Read from the
    // machine's own resources rather than from a number typed here: the
    // seeded plan is one vCPU, and a spec that asserted two would be
    // asserting the fixture.
    await expect(page.getByText(/\d+ vCPU/).first()).toBeVisible()
    await expect(page.getByText(fixtures.operableAddress)).toBeVisible()

    /*
     * What it costs. Either the chain the server published — the plan, what
     * renews it, the order and the invoice — or the honest sentence for a
     * machine with no subscription recorded against it. What must not happen
     * is a section that says nothing at all.
     */
    await section(page, /^billing$/i)
    await expect(page.getByRole('heading', { name: /^billing$/i })).toBeVisible()
    await expect(
      page.getByText(/renews|ends|nothing renews this/i).first(),
    ).toBeVisible()

    await nothingOperational(page)
  })

  test('its sections are addresses a customer can send, refresh and bookmark', async ({ page }) => {
    await open(page, '/vps', fixtures.operableHostname)

    await section(page, /^networking$/i)
    await expect(page).toHaveURL(/\/vps\/[^/]+\/networking$/)

    const address = page.url()

    // Reloaded, the way a colleague opening the link would arrive: the page
    // is the section, not a tab somebody has to find again.
    await page.reload()
    await expect(page.getByText(fixtures.operableAddress)).toBeVisible()
    expect(page.url()).toBe(address)

    // And the section the reader is on says so, for a screen reader too.
    await expect(
      page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name: /^networking$/i }),
    ).toHaveAttribute('aria-current', 'page')
  })

  test('reaches its backups from the machine, and the global screen still works', async ({
    page,
  }) => {
    /*
     * The audit's complaint was not that /backups existed. It was that it was
     * the only way in — a customer looking at a server had to know backups
     * lived on another screen behind a picker.
     */
    await open(page, '/vps', fixtures.vpsHostname)
    await section(page, /^backups$/i)

    await expect(page.getByRole('table', { name: /backups/i })).toBeVisible()

    // The same section, and the same archives, from the cross-machine screen.
    await page.goto('/backups')
    await page.getByRole('combobox').selectOption({ label: fixtures.vpsHostname })
    await expect(page.getByRole('table', { name: /backups/i })).toBeVisible()
  })

  test('offers the images the platform staged for it, and public keys where they work', async ({
    page,
  }) => {
    await open(page, '/vps', fixtures.operableHostname)
    await section(page, /^danger zone$/i)

    await page.getByRole('button', { name: /^reinstall$/i }).click()
    const dialog = page.getByRole('dialog')

    // The default is the honest one: the same image again, which is what the
    // endpoint does with no template at all.
    const images = dialog.getByRole('combobox', { name: /operating system/i })
    await expect(images).toBeVisible()
    await expect(images.getByRole('option').first()).toHaveText(/keep the current image/i)

    /*
     * And at least one real image from the platform's own staged list. The
     * list arrives in its own request, so this has to be an assertion that
     * retries: a count read once races the response, and the race is silent
     * because the default option is already there.
     */
    await expect(images.getByRole('option').filter({ hasText: /debian/i })).toHaveCount(1)

    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})

test.describe('the other five families', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('a dedicated machine names its hardware and never its management path', async ({ page }) => {
    await open(page, '/dedicated', fixtures.dedicatedSerial)

    await expect(page.getByText(fixtures.dedicatedSerial).first()).toBeVisible()

    // The chassis power controls are here, on the API's own word.
    await expect(page.getByRole('button', { name: /^power on$/i })).toBeVisible()

    /*
     * And nothing about how the platform reaches it. A customer never needs
     * the BMC address, the rack or the credential, and the page must not be
     * the place they find one.
     */
    await nothingOperational(page)
  })

  test('a hosting account names its plan, its panel and its real usage', async ({ page }) => {
    await open(page, '/hosting', fixtures.hostingDomain)

    // The panel, named or honestly generic — never a claim the platform
    // cannot make.
    await expect(page.getByText(/cpanel|directadmin|hosting control panel/i).first()).toBeVisible()

    // The usage reading, with the platform's own caveat about it.
    await expect(page.getByRole('heading', { name: /^usage$/i })).toBeVisible()
    await expect(
      page.getByText(/measured|has not had a reading from the panel/i).first(),
    ).toBeVisible()

    await nothingOperational(page)
  })

  test('a WordPress site groups its copies without changing what they promise', async ({ page }) => {
    await open(page, '/wordpress', fixtures.liveSite)

    await expect(page.getByRole('heading', { name: /^progress$/i })).toBeVisible()

    await section(page, /^copies$/i)
    await expect(page.getByRole('button', { name: /create a staging copy/i })).toBeVisible()
  })

  test('a domain shows its renewal, its registrant and the way out', async ({ page }) => {
    await open(page, '/domains', fixtures.heldDomain)

    // The renewal, said as a setting rather than as a cancellation.
    await expect(page.getByText(/auto-renew is (on|off)/i)).toBeVisible()
    await expect(page.getByText(/this is not a cancellation|an invoice is raised/i).first()).toBeVisible()

    // The registrant, read back so a correction need not be retyped.
    await section(page, /^registrant$/i)
    await expect(page.getByLabel(/full name/i)).toHaveValue(/.+/)
    await expect(page.getByText(/nothing on this form is written to a log/i)).toBeVisible()

    // And the two controls somebody needs in order to leave.
    await section(page, /^moving away$/i)
    await expect(page.getByRole('button', { name: /transfer code/i })).toBeEnabled()
  })

  test('a zone is addressed by its name and leads back to the registration', async ({ page }) => {
    await open(page, '/dns', fixtures.heldDomain)

    await expect(page).toHaveURL(new RegExp('/dns/' + fixtures.heldDomain + '$'))
    await expect(page.getByText(/does not check who owns a domain/i)).toBeVisible()

    await page.getByRole('link', { name: /domain registration/i }).click()
    await expect(page.getByRole('heading', { level: 1, name: fixtures.heldDomain })).toBeVisible()
  })
})

test.describe('the resources reach each other', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('the services index names each thing and opens it', async ({ page }) => {
    await page.goto('/services')

    // The identity, not the plan label shared by every customer on the plan.
    const row = page.getByRole('row').filter({ hasText: fixtures.operableHostname })
    await expect(row).toBeVisible()

    await row.getByRole('link', { name: fixtures.operableHostname }).click()
    await expect(page.getByRole('heading', { level: 1, name: fixtures.operableHostname })).toBeVisible()
    await expect(page).toHaveURL(/\/vps\/[^/]+$/)
  })

  test('a subscription opens the machine it pays for', async ({ page }) => {
    await page.goto('/subscriptions')

    /*
     * The seeded ending subscription pays for e2e-web-01. Cancelling the
     * wrong one of two identical rows is how a customer loses a production
     * machine, which is why the row names the machine — and now links to it,
     * at the machine's own id rather than the service's.
     */
    const machine = page.getByRole('link', { name: fixtures.vpsHostname }).first()
    await expect(machine).toBeVisible()

    await machine.click()
    await expect(page).toHaveURL(/\/vps\/[^/]+$/)
    await expect(page.getByRole('heading', { level: 1, name: fixtures.vpsHostname })).toBeVisible()
  })

  test('a notification opens the thing it is about', async ({ page }) => {
    await page.goto('/notifications')

    /*
     * The seeded inbox carries a service notification and an invoice one, both
     * with their subject on the row, because that is what the real notifier
     * writes. The row's way in is the resource the API resolved from that
     * subject — a machine, not the list of machines, which is where every
     * service notification used to land.
     */
    const opens = page.getByRole('link', { name: /^open$/i })
    await expect(opens.first()).toBeVisible()

    const deepLinks = await opens.evaluateAll((links) =>
      links.map((link) => link.getAttribute('href') ?? ''),
    )

    expect(deepLinks.some((href) => /\/vps\/[^/]+$/.test(href))).toBe(true)
    expect(deepLinks.some((href) => /\/invoices\/[^/]+$/.test(href))).toBe(true)

    // And following one arrives at the machine itself.
    await page.getByRole('link', { name: /^open$/i }).first().click()
    await expect(page).toHaveURL(/\/(vps|invoices)\/[^/]+$/)
  })

  test('every address that existed before this wave still answers', async ({ page }) => {
    for (const [address, heading] of [
      ['/services', /^services$/i],
      ['/vps', /^cloud vps$/i],
      ['/dedicated', /^dedicated servers$/i],
      ['/hosting', /^shared hosting$/i],
      ['/wordpress', /^wordpress$/i],
      ['/domains', /^domains$/i],
      ['/dns', /^dns$/i],
      ['/backups', /^backups$/i],
      ['/ips', /^ip addresses$/i],
    ] as Array<[string, RegExp]>) {
      await page.goto(address)
      await expect(page.getByRole('heading', { name: heading }).first()).toBeVisible()
    }
  })

  test("an id that is not this account's shows a refusal rather than a broken page", async ({
    page,
  }) => {
    // A real-shaped ULID belonging to nobody. The page asks the API and is
    // answered 404; what a customer must not get is a blank screen.
    await page.goto('/vps/01JZZZZZZZZZZZZZZZZZZZZZZZ')

    await expect(page.getByRole('alert').first()).toBeVisible()
  })
})

test.describe('the shell at the widths customers read it at', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  for (const [label, width, height] of [
    ['a wide desktop', 1440, 900],
    ['the breakpoint itself', 1024, 800],
  ] as Array<[string, number, number]>) {
    test(`${label} keeps every destination in the sidebar and never scrolls sideways`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height })
      await open(page, '/vps', fixtures.operableHostname)

      // The persistent column, at and above the breakpoint.
      const sidebar = page.getByRole('navigation', { name: /main navigation/i }).first()
      await expect(sidebar).toBeVisible()
      await expect(sidebar.getByRole('link', { name: /^backups$/i })).toBeVisible()

      // Nothing behind a disclosure, which is what hid seventeen destinations.
      await expect(page.getByRole('button', { name: /^more$/i })).toHaveCount(0)

      await noSidewaysScroll(page)

      // Including the section with a table in it, which is where a resource
      // page overflows if a table is not given its own scroller.
      await section(page, /^networking$/i)
      await noSidewaysScroll(page)
    })
  }

  test('a narrow window hides the column and offers the drawer instead', async ({ page }) => {
    await page.setViewportSize({ width: 900, height: 800 })
    await page.goto('/vps')

    await expect(page.getByRole('navigation', { name: /main navigation/i })).toBeHidden()
    await expect(page.getByRole('button', { name: /^menu$/i })).toBeVisible()

    await noSidewaysScroll(page)
  })
})
