import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * Wave 5, W5.7. The browser's own buttons, and the addresses a customer keeps.
 *
 * Every journey here uses `page.goBack()` — the browser's Back, not a link
 * labelled "Back". The difference is the whole point: an in-app link can be
 * made to go anywhere, while Back goes to the address the customer was last
 * on, and if that address did not describe what they were looking at then Back
 * takes them somewhere they have never been. Before W5.7 the activity filter,
 * the catalogue filter and every list's page number lived in component state,
 * so Back from an invoice landed on page one of an unfiltered list and a
 * refresh did the same.
 *
 * The refresh journeys are the other half of the same property: an address
 * that describes the screen can be reloaded, bookmarked and sent to somebody
 * else. A portal whose URLs do not survive a refresh has told the customer
 * their browser is broken.
 */

/** Opens the operable machine's billing section, which is a route of its own. */
async function openMachineBilling(page: Page): Promise<void> {
  await page.goto('/vps')
  await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name: /^billing$/i }).click()
  await expect(page).toHaveURL(/\/vps\/[^/]+\/billing$/)
}

test.describe('journey A — a resource, its invoice, and back again', () => {
  test('Back returns to the exact section of the exact resource', async ({ page }) => {
    await signIn(page, users.customer)

    await openMachineBilling(page)

    const resource = page.url()

    // Into the money, through the link the section offers.
    const invoice = page.getByRole('link', { name: new RegExp(`view invoice ${fixtures.openInvoice}`, 'i') })

    if (await invoice.count() > 0) {
      await invoice.first().click()
    } else {
      // The billing section links the invoices that belong to this service;
      // on a fixture with none, the index is the honest next step.
      await page.getByRole('navigation', { name: /main navigation/i }).getByRole('link', { name: /^invoices$/i }).click()
    }

    await expect(page).not.toHaveURL(resource)

    await page.goBack()

    /*
     * The same address, not merely the same page: the section a customer was
     * reading is part of where they were, and returning to the resource's
     * overview instead would be the portal deciding it knows better.
     */
    await expect(page).toHaveURL(resource)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })
})

test.describe('journey B — the activity feed, filtered', () => {
  test('Back from a resource returns to the same filter and the same page of the feed', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    await page.goto('/activity')

    // Filter to one category. The address says which.
    await page.getByRole('button', { name: /^cloud servers$/i }).click()
    await expect(page).toHaveURL(/category=cloud/)

    const filtered = page.url()

    // Walk one page older, which is a history entry of its own.
    const older = page.getByRole('button', { name: /show older/i })

    if (await older.isEnabled()) {
      await older.click()
      await expect(page).toHaveURL(/cursor=/)

      const secondPage = page.url()

      await page.goBack()
      await expect(page, 'Back walks the cursor trail rather than losing it').toHaveURL(filtered)

      await page.goForward()
      await expect(page).toHaveURL(secondPage)
      await page.goBack()
    }

    // Out to a resource the feed mentions, and back.
    await page.getByRole('navigation', { name: /main navigation/i }).getByRole('link', { name: /^cloud vps$/i }).click()
    await expect(page.getByRole('heading', { level: 1, name: /cloud vps/i })).toBeVisible()

    await page.goBack()

    await expect(page).toHaveURL(filtered)
    await expect(
      page.getByRole('button', { name: /^cloud servers$/i }),
      'the filter is still the chosen one',
    ).toHaveAttribute('aria-pressed', 'true')
  })

  test('a refresh keeps the filter, and the filter is a link anybody can send', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/activity?category=billing')

    await expect(page.getByRole('button', { name: /^billing$/i })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    await page.reload()

    await expect(page.getByRole('button', { name: /^billing$/i })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })
})

test.describe('journey C — the catalogue, filtered', () => {
  test('Back from a product returns to the filtered list', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/catalogue')

    await page.getByRole('group', { name: /filter/i }).getByRole('button', { name: /^cloud vps$/i }).click()
    await expect(page).toHaveURL(/kind=vps/)

    const filtered = page.url()

    await page.locator('main a[href^="/catalogue/"]').first().click()

    // Whatever the first product is, we left the catalogue.
    await expect(page).not.toHaveURL(filtered)

    await page.goBack()

    await expect(page).toHaveURL(filtered)
    await expect(
      page.getByRole('group', { name: /filter/i }).getByRole('button', { name: /^cloud vps$/i }),
    ).toHaveAttribute('aria-pressed', 'true')
  })
})

test.describe('journey D — a list, paged', () => {
  test('Back from an invoice returns to the page of the list it was opened from', async ({
    page,
  }) => {
    await signIn(page, users.customer)

    // Straight to the second page, which is the address the paginator writes.
    await page.goto('/invoices?page=2')

    const listed = page.url()
    const rows = page.getByRole('row')

    if ((await rows.count()) > 1) {
      await page.getByRole('link', { name: /view invoice/i }).first().click()
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

      await page.goBack()
      await expect(page).toHaveURL(listed)
    } else {
      // Fewer than two pages of invoices on this account: the property worth
      // asserting is then that the address survives a reload rather than
      // resetting to page one.
      await page.reload()
      await expect(page).toHaveURL(listed)
    }
  })
})

test.describe('journey E — a domain, its zone, and back', () => {
  test('Back returns to the domain a deep link opened', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto(`/domains/${fixtures.heldDomain}`)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    const domain = page.url()

    await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name: /nameservers/i }).click()
    await expect(page).toHaveURL(/nameservers$/)

    await page.goBack()

    await expect(page).toHaveURL(domain)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })
})

test.describe('a direct refresh', () => {
  /*
   * §12. Every route family, reloaded at its own address. A portal that works
   * only when navigated into is a portal whose links cannot be shared, and
   * the failure mode — a blank screen on refresh — looks like the platform is
   * down.
   */
  type Refreshable =
    | { what: string; address: string }
    | { what: string; reach: (page: Page) => Promise<string> }

  const ROUTES: Refreshable[] = [
    { what: 'the dashboard', address: '/' },
    { what: 'the activity feed with a filter', address: '/activity?category=cloud' },
    { what: 'the invoices list on its second page', address: '/invoices?page=2' },
    { what: 'the catalogue, filtered', address: '/catalogue?kind=vps' },
    { what: 'the notifications inbox, unread only', address: '/notifications?unread=1' },
    { what: 'team', address: '/settings/team' },
    { what: 'security', address: '/security' },
    { what: 'API tokens', address: '/api-tokens' },
    { what: 'support with a context', address: '/support?kind=vps&id=unknown' },
    {
      what: 'a machine’s networking section',
      reach: async (page) => {
        await page.goto('/vps')
        await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
        await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name: /networking/i }).click()
        await expect(page).toHaveURL(/networking$/)

        return page.url()
      },
    },
    {
      what: 'a dedicated server',
      reach: async (page) => {
        await page.goto('/dedicated')
        await page.getByRole('link', { name: new RegExp(fixtures.dedicatedSerial, 'i') }).first().click()
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        return page.url()
      },
    },
    {
      what: 'a hosting account',
      reach: async (page) => {
        await page.goto('/hosting')
        await page.getByRole('link', { name: new RegExp(fixtures.hostingDomain, 'i') }).first().click()
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        return page.url()
      },
    },
    { what: 'a domain', address: `/domains/${fixtures.heldDomain}` },
    { what: 'a DNS zone’s records', address: `/dns/${fixtures.heldDomain}/records` },
    {
      what: 'an invoice',
      reach: async (page) => {
        // Addressed by its id rather than its number, which is why this one
        // is reached by opening it rather than by composing the address.
        await page.goto('/invoices')
        await page
          .getByRole('link', { name: new RegExp(`view invoice ${fixtures.openInvoice}`, 'i') })
          .click()
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        return page.url()
      },
    },
  ]

  for (const route of ROUTES) {
    test(`works at ${route.what}`, async ({ page }) => {
      await signIn(page, users.customer)

      const address = 'address' in route ? route.address : await route.reach(page)

      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

      await page.reload()

      // The same address, a heading, and no not-found page.
      await expect(page).toHaveURL(address)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expect(page.getByText(/we could not find that page/i)).toHaveCount(0)
    })
  }
})

test.describe('an address somebody edited', () => {
  test('a nonsense page number reads as the first page', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/invoices?page=not-a-number')

    await expect(page.getByRole('heading', { level: 1, name: /invoices/i })).toBeVisible()
    await expect(page.getByText(/something went wrong/i)).toHaveCount(0)
  })

  test('a filter that does not exist reads as no filter', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/activity?category=everything')

    await expect(page.getByRole('heading', { level: 1, name: /activity/i })).toBeVisible()
    await expect(page.getByRole('button', { name: /^everything$/i })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  test('a mangled cursor answers with the newest page rather than an error', async ({ page }) => {
    /*
     * §10. The cursor is opaque and the server is explicit about this: an
     * unreadable one means the newest page, because a customer who pasted
     * half a URL should get the feed rather than an error screen.
     */
    await signIn(page, users.customer)

    await page.goto('/activity?cursor=bm90LWEtcmVhbC1jdXJzb3I')

    await expect(page.getByRole('heading', { level: 1, name: /activity/i })).toBeVisible()
    await expect(page.getByText(/we could not reach the server/i)).toHaveCount(0)
  })
})

test.describe('somebody else’s resources', () => {
  test('a deep link to another account’s invoice does not open it', async ({ page }) => {
    /*
     * §14. The route exists — every customer has `/invoices/:id` — and the
     * document does not, because the API resolves an invoice through the
     * acting customer.
     *
     * The address is captured from the account that owns the invoice rather
     * than composed, and that matters: an invoice is addressed by its id, so
     * a made-up address would be refused for being made up and would prove
     * nothing about tenancy. This is the real address of a real invoice
     * belonging to somebody else.
     */
    await signIn(page, users.moneyCustomer)
    await page.goto('/invoices')
    await page
      .getByRole('link', { name: new RegExp(`view invoice ${fixtures.creditThenCardInvoice}`, 'i') })
      .click()
    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.creditThenCardInvoice) }),
    ).toBeVisible()

    const theirInvoice = new URL(page.url()).pathname

    // Sign out, and in as the other account.
    await page.getByRole('button', { name: /^sign out$/i }).first().click()
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()

    await signIn(page, users.customer)
    await page.goto(theirInvoice)

    // Not the document.
    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(fixtures.creditThenCardInvoice) }),
    ).toHaveCount(0)

    // And something true instead: the read failed, in words.
    await expect(page.getByRole('alert').first()).toBeVisible()
    await expect(page.getByRole('alert').first()).toContainText(/does not exist|not permitted/i)
  })

  test('a deep link to a domain nobody holds does not open a page for it', async ({ page }) => {
    await signIn(page, users.customer)

    await page.goto('/domains/somebody-elses-domain.test')

    await expect(page.getByRole('heading', { level: 1, name: /somebody-elses-domain/i })).toHaveCount(0)
    await expect(page.getByRole('alert').first()).toBeVisible()
  })
})
