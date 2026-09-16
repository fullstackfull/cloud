import { expect, test, type Page } from '@playwright/test'

import { expectNoPageOverflow, pageOverflow } from '../support/layout'
import { signIn, users } from '../support/helpers'

/*
 * §6, §11. What a long technical value does to a narrow screen.
 *
 * Every value here is created through the product's own forms, with the kind
 * of thing somebody actually types rather than lorem: a hostname long enough
 * to describe an environment, a full IPv6 address with no zero compression, a
 * CIDR block, a support subject written by somebody who is annoyed.
 *
 * What is being asserted is the strategy, not the appearance. §6 allows four
 * answers — wrap, scroll locally, truncate with the full value available, or
 * some other deliberate arrangement — and forbids exactly one outcome: a
 * single technical string breaking the page shell. So the measurement is the
 * page's own geometry, taken after the value is on the screen.
 *
 * The fix this covers is one line in the design system rather than a hundred
 * at the call sites: `.technical` now sets `overflow-wrap: anywhere`, because
 * a technical value is one unbreakable word and 143 of the 155 places that
 * render one said nothing at all about wrapping. `anywhere` rather than
 * `break-word` on purpose — only `anywhere` lowers the box's min-content
 * width, which is the part that lets a flex item or a table cell shrink
 * around a 48-character token instead of refusing to.
 */

/**
 * A suffix that makes a fixture this spec creates belong to this run.
 *
 * These specs run in two projects against one database, so a zone, a token or
 * a ticket named the same thing in both leaves two of it. The answer is not
 * `.first()`: that hides the duplicate instead of removing it. The viewport
 * width is what differs between the projects, so it is what names the fixture.
 */
function own(page: Page): string {
  return String(page.viewportSize()?.width ?? 0)
}

/*
 * A zone this spec claims and gives back. It shares a database with the phone
 * and Arabic projects, so a fixture it leaves behind is a fixture another
 * project did not expect to find.
 */
const LONG_ZONE = (page: Page): string =>
  `staging-environment-eu-west-${own(page)}.example-company-holdings.test`

/** A full IPv6 address, written out: no `::`, so it is as wide as it gets. */
const FULL_IPV6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334'

test.describe('a long technical value on a narrow screen', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('folds a long domain and a full IPv6 address rather than breaking the page', async ({ page }) => {
    const zone = LONG_ZONE(page)

    await page.goto('/dns')
    await page.getByLabel(/^domain$/i).first().fill(zone)
    await page.getByRole('button', { name: /add domain/i }).click()

    /*
     * The index first: a 60-character name in a table row, and the row also
     * carries a status and an action.
     */
    await expect(page.getByRole('link', { name: zone })).toBeVisible()
    await expectNoPageOverflow(page, `the zone list holding ${zone}`)

    // Then the zone's own page, where the name is the h1.
    await page.getByRole('link', { name: zone }).click()
    await expect(page.getByRole('heading', { level: 1, name: zone })).toBeVisible()
    await expectNoPageOverflow(page, 'the zone page, titled by a long name')

    const base = new URL(page.url()).pathname
    await page.goto(`${base}/records`)

    /*
     * An AAAA record: a long owner name on the left and a full IPv6 address as
     * the value. Both are `.technical`, and before the wrapping rule the
     * record table was the widest thing in the portal.
     */
    await page.getByLabel(/name \(blank for/i).fill('mail-relay-outbound-01')
    await page.getByLabel(/^type$/i).selectOption('AAAA')
    await page.getByLabel(/^value$/i).fill(FULL_IPV6)
    await page.getByRole('button', { name: /add record/i }).click()

    const record = page.getByRole('row').filter({ hasText: 'mail-relay-outbound-01' })
    await expect(record).toHaveCount(1)
    await expect(record).toContainText(FULL_IPV6)

    await expectNoPageOverflow(page, 'the records table with a full IPv6 address in it')

    /*
     * And the value is readable in full rather than clipped. The row may wrap
     * it over two lines — that is one of the four strategies §6 allows — but
     * it must not be cut off with no way to see the rest, which §23 forbids.
     */
    const cell = record.locator('td').filter({ hasText: FULL_IPV6 }).first()
    const clipped = await cell.evaluate((element) => element.scrollWidth - element.clientWidth)
    expect(clipped, 'the address is cut off inside its cell').toBeLessThanOrEqual(1)

    // Given back: the record, then the zone.
    await record.getByRole('button', { name: /^remove$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^remove record$/i }).click()
    await expect(page.getByRole('row').filter({ hasText: 'mail-relay-outbound-01' })).toHaveCount(0)

    await page.goto(`${base}/danger`)
    await page.getByRole('button', { name: /give up domain/i }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(zone)
    await dialog.getByRole('button', { name: /give up domain/i }).click()
    await expect(page).toHaveURL(/\/dns$/)
  })

  test('shows a whole API token, once, and completely', async ({ page }) => {
    /*
     * The hardest string in the portal. A Sanctum token is `id|plaintext` —
     * here 75 characters — with no space, no hyphen and no break opportunity
     * anywhere in it, rendered in a monospace block inside a card with no
     * scroll container. It is also the one value a customer must be able to
     * read every character of, because the platform will never show it again:
     * the table holds a SHA-256 digest and there is no endpoint that returns
     * the plaintext a second time.
     *
     * Two defects met on this screen, and both were invisible.
     *
     * The first: the page read `plain_text_token` from a response that has no
     * such key — the secret arrives as `token` — so `undefined` was handed to
     * the paragraph that renders it. A customer who created a token was shown
     * an empty grey box under the words "this is the only time it is shown".
     * Nothing threw, nothing warned, and a screenshot showed a tidy empty
     * box, because the response type was hand-written to match a shape the
     * server never sends.
     *
     * The second: with the secret restored, 75 unbreakable characters in a
     * 302px block is 41px of page overflow — until `.technical` wraps, which
     * is what the rule in index.css is for.
     *
     * So this test asserts the secret is present, is long, is complete, and
     * fits. The first assertion is the one that matters most: a test that only
     * measured the box would have passed against an empty one. It did.
     */
    await page.goto('/api-tokens')
    const token = `Deployment pipeline ${own(page)}`
    await page.getByLabel(/^name$/i).fill(token)
    await page.getByLabel(/current password/i).fill(users.customer.password)
    await page.getByRole('button', { name: /^create token$/i }).click()

    const secret = page.locator('code')
    await expect(secret).toBeVisible()

    const shown = (await secret.textContent()) ?? ''

    expect(shown.length, 'the token was not shown, and it will never be shown again')
      .toBeGreaterThan(30)
    /*
     * `id|plaintext`, with the identifier being a ULID rather than a number.
     * Asserted as a shape rather than a length because the shape is what
     * authenticates: the server splits on the pipe, looks the identifier up,
     * and hashes the rest. Half of this value is not a weaker credential, it
     * is not a credential.
     */
    expect(shown, 'a token is `id|plaintext`; half of it does not authenticate').toMatch(
      /^[A-Za-z0-9]+\|[A-Za-z0-9]+$/,
    )

    /*
     * Complete rather than clipped: the box may wrap it over three lines — it
     * does — but `scrollWidth` beyond `clientWidth` would mean characters a
     * customer cannot see and cannot scroll to.
     */
    const clipped = await secret.evaluate((element) => element.scrollWidth - element.clientWidth)
    expect(clipped, 'the token is cut off and cannot be read in full').toBeLessThanOrEqual(1)

    await expectNoPageOverflow(page, 'the credential screen with a whole token on it')

    const row = page.getByRole('row').filter({ hasText: token })
    await row.getByRole('button', { name: /^revoke$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^revoke token$/i }).click()
    await expect(row).toContainText(/Revoked/)
  })

  test('folds a CIDR block in the credential restrictions', async ({ page }) => {
    await page.goto('/api-tokens')

    /*
     * Three address ranges, one per line, including a full IPv6 prefix. The
     * list is `.technical` and sits in a table cell next to a status and an
     * action.
     */
    const runners = `Continuous integration runners ${own(page)}`
    await page.getByLabel(/^name$/i).fill(runners)
    await page.getByLabel(/current password/i).fill(users.customer.password)
    await page
      .getByLabel(/only from these addresses/i)
      .fill(`198.51.100.0/24\n203.0.113.17/32\n${FULL_IPV6}/64`)
    await page.getByRole('button', { name: /^create token$/i }).click()

    const row = page.getByRole('row').filter({ hasText: runners })
    await expect(row).toHaveCount(1)
    await expectNoPageOverflow(page, 'the credential list with CIDR restrictions')

    await row.getByRole('button', { name: /^revoke$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^revoke token$/i }).click()
    await expect(row).toContainText(/Revoked/)
  })

  test('folds a long support subject, in English and in Arabic', async ({ page }) => {
    const subject =
      'Outbound mail from the staging relay is being rejected with 550 5.7.1 since the ' +
      `weekend (${own(page)})`

    await page.goto('/support')
    await page.getByLabel(/^subject$/i).fill(subject)
    await page.getByLabel(/what is happening/i).fill(
      'كل الرسائل الصادرة من موزّع البريد في بيئة التجربة تُرفض منذ صباح السبت، ' +
        'والرسالة التي تعود من الخادم هي 550 5.7.1 بدون تفسير إضافي.',
    )
    await page.getByRole('button', { name: /send request/i }).click()

    /*
     * A long subject is not a technical value, but it lands in the same
     * places: a list row, a heading, a notification. Arabic body text beside a
     * Latin error code is the bidirectional case §7 cares about, and it is
     * here rather than in a separate spec because this is the screen where a
     * customer actually types both.
     */
    /*
     * Named exactly, so it is this run's ticket. A ticket is not deletable, so
     * the other narrow project's ticket is still in the list — matching on a
     * prefix and taking the first would be picking one of two at random.
     */
    const ticket = page.getByRole('button', { name: subject })
    await expect(ticket).toBeVisible()
    await expectNoPageOverflow(page, 'the ticket list with a long subject')

    await ticket.click()
    await expect(page.getByText('550 5.7.1').first()).toBeVisible()
    expect(await pageOverflow(page), 'the ticket, with Arabic prose and a Latin code in it')
      .toBeLessThanOrEqual(1)
  })
})
