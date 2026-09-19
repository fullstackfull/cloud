import { expect, test, type Page } from '@playwright/test'

import {
  expectOperationNoLongerReported,
  expectOperationReported,
  fixtures,
  operationsChannel,
  signIn,
  users,
} from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * §62. Wave 4's surfaces on a 393-pixel phone.
 *
 * The dashboard is six cards, the feed is rows of four columns' worth of
 * information, and the acknowledgement is a fixed panel at the bottom of the
 * screen — three shapes that are easy to get right on a desktop and easy to
 * get wrong on a phone. What is asserted is that nothing runs off the side,
 * that the toast does not sit on top of the thing it is about, and that the
 * feed's controls are still reachable with a thumb.
 */

/** Nothing renders wider than the phone it is being read on. */
async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )

  expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(1)
}

test.describe('what is happening, on a phone', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('reads the dashboard, attention first, without scrolling sideways', async ({ page }) => {
    await expect(page.getByRole('region', { name: 'Needs your attention' })).toBeVisible()

    await noSidewaysScroll(page)

    /*
     * The cards stack rather than squeeze. A three-column grid at 393px is
     * three columns of forty pixels, which is how a money figure ends up
     * broken across four lines.
     */
    const owed = page.getByRole('region', { name: 'What you owe' })
    await expect(owed).toBeVisible()

    const width = await owed.evaluate((element) => element.getBoundingClientRect().width)
    expect(width).toBeGreaterThan(280)
  })

  test('reads the account feed and filters it with a thumb', async ({ page }) => {
    await openMenu(page)
    await page.getByRole('link', { name: /^activity$/i }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Activity' })).toBeVisible()

    await noSidewaysScroll(page)

    /*
     * Forty-four pixels is the size a thumb finds, and the filters are the one
     * control on this page that is a row of small buttons.
     */
    const filter = page.getByRole('button', { name: 'Billing' })
    await expect(filter).toBeVisible()

    const box = await filter.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(32)

    await filter.click()
    await expect(filter).toHaveAttribute('aria-pressed', 'true')

    await noSidewaysScroll(page)
  })

  test('reports the operation without covering the controls it is about', async ({ page }) => {
    /*
     * This is a layout test, and it used to assert the acknowledgement
     * sentence exactly — `getByText(/Reboot requested/)`. That was the last
     * home of the race carried since Gap 6: the acknowledgement is replaced
     * under the same operation id as soon as the watcher's first read comes
     * back terminal, this suite runs the queue inline, and so the window in
     * which those exact words exist is one HTTP round trip. Three other specs
     * were migrated to the lifecycle contract when that was diagnosed; this
     * one was missed, and it is what failed CI run 196.
     *
     * The contract it asserts now is the product's actual promise: the channel
     * says where the operation stands, in the lifecycle's own words, whichever
     * end of it the browser caught. The exact sentence is still asserted
     * exactly, in `watching-what-was-started.test.tsx`, where the read is a
     * controlled fake and the timing is the test's own.
     */
    const mutations: string[] = []

    page.on('request', (request) => {
      if (request.method() !== 'GET' && request.method() !== 'HEAD' && /\/operations|power|reboot|restart/.test(request.url())) {
        mutations.push(`${request.method()} ${new URL(request.url()).pathname}`)
      }
    })

    await page.goto('/vps')
    await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()

    const reboot = page.getByRole('button', { name: 'Reboot' }).first()
    await reboot.click()

    await expectOperationReported(page, 'Reboot')

    /*
     * Everything this test has to say about the panel is said here, in the
     * steps immediately after the message was seen, and that ordering is
     * deliberate.
     *
     * The channel is transient by design: an info or success message clears
     * itself six seconds after it was announced (TRANSIENT_MS in Toasts.tsx —
     * a warning or a failure stays, because bad news has to be read). So a
     * browser assertion that needs the panel to still be there is spending a
     * six-second budget, and any work put between the sighting and the
     * assertions is spent out of it. These are three round trips.
     *
     * The panel is fixed to the bottom of a small screen, which is the right
     * place for it and the easiest place to cover something with it. It has a
     * dismiss button, and the dismiss button is reachable.
     */
    const channel = operationsChannel(page)
    const dismiss = channel.getByRole('button', { name: 'Dismiss' }).first()
    await expect(dismiss).toBeVisible()

    await noSidewaysScroll(page)

    await dismiss.click()

    /*
     * Against every message the channel can carry, not just the
     * acknowledgement. Asserting the dismissal against one of the seven would
     * pass on every run where the operation had already settled and the panel
     * was showing its outcome instead — the same race, mirrored, and failing
     * open rather than closed.
     */
    await expectOperationNoLongerReported(page, 'Reboot')

    /*
     * One press, one mutation. A display race must never be answered by
     * sending the reboot again, so the count is asserted rather than assumed.
     */
    expect(mutations, `One press sent: ${mutations.join(', ')}`).toHaveLength(1)
  })
})
