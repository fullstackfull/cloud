import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * What the sweeps found, on the screen somebody reads them on.
 *
 * Two sweeps in this phase produce nothing but findings: hosting
 * reconciliation, which compares this platform against a panel and repairs
 * nothing, and the provider task poller, which turns "the request was
 * accepted" into "the machine was really built" — or into a job in review.
 *
 * Both were proven in PHPUnit before this file existed, which proved they
 * write rows. A row nobody can read is a log file with a primary key. These
 * specs assert the rest of the journey: that the finding reaches a screen,
 * that it says which resource it is about, and that an operator can act on it
 * without a button that pretends to fix the provider.
 *
 * The fixtures come from `E2ESeeder::whatReconciliationFound`.
 */

/**
 * The drift row the specs read: the account this platform has and the panel
 * has never heard of.
 *
 * Matched on the kind rather than on the username, because the seeder plants
 * a second finding about the same account for the spec that closes one — and
 * a locator that matched both would resolve to two rows.
 */
function missingAtProvider(page: Page) {
  return page.getByRole('row').filter({ hasText: /missing at the provider|غير موجود لدى المزوّد/i })
}

/** The finding the closing spec consumes. Nothing else asserts on it. */
function orphanAtProvider(page: Page) {
  return page.getByRole('row').filter({ hasText: /nothing claims it/i })
}

test.describe('an operator reading what the sweeps found', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
  })

  test('sees a hosting account the panel has never heard of, and which resource it is', async ({
    page,
  }) => {
    await page.goto('/admin/drift')

    await expect(page.getByRole('heading', { name: /^drift$/i })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    const finding = missingAtProvider(page)
    await expect(finding).toBeVisible()

    /*
     * The kind, translated. Before this phase, three of the five drift kinds
     * had no translation at all and rendered as their raw enum value, which is
     * how `orphan_at_provider` reached an operator's screen.
     */
    await expect(finding).toContainText(/missing at the provider/i)

    // Which resource, and which one of them. Two findings of the same kind
    // about a machine and about a hosting account are triaged differently, and
    // before this phase the screen showed neither.
    await expect(finding).toContainText(/hosting_account/)
    await expect(finding).toContainText(fixtures.hostingUsername)

    // Both sides, so the operator can see what the disagreement actually is
    // rather than taking the severity's word for it.
    await expect(finding).toContainText(/"present":\s*false/)
  })

  test('can record a finding as no longer true, and only with a written reason', async ({
    page,
  }) => {
    await page.goto('/admin/drift')

    await orphanAtProvider(page).getByRole('button', { name: /no longer true/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The dialogue says plainly that this changes nothing at the provider.
    await expect(dialog).toContainText(/nothing here changes a provider/i)

    const confirm = dialog.getByRole('button', { name: /no longer true/i })
    await expect(confirm).toBeDisabled()

    await dialog.locator('textarea').fill('the account was recreated on the panel by hand')
    await expect(confirm).toBeEnabled()

    await confirm.click()

    await expect(dialog).toBeHidden()

    // Open findings only, by default — a resolved one leaves the list.
    await expect(orphanAtProvider(page)).toHaveCount(0)

    // And is still there, with the reason, when the filter is lifted.
    await page.getByRole('button', { name: /open findings only/i }).click()
    await expect(orphanAtProvider(page)).toContainText(/recreated on the panel by hand/i)
  })

  test('sees the build whose hypervisor task never came back, and is not told it succeeded', async ({
    page,
  }) => {
    await page.goto('/admin/provisioning')

    await expect(page.getByRole('heading', { name: /provisioning queue/i })).toBeVisible()

    /*
     * The seeded job's own words. What matters is that it is *here* at all: the
     * job itself completed — the hypervisor accepted the request — and only the
     * poller's second question turned that into a job somebody has to look at.
     */
    const review = page.getByRole('status').filter({ hasText: /needs? a human/i })
    await expect(review).toContainText(/has not finished/i)
    await expect(review).toContainText(/timeout/i)
  })
})

test.describe('on the Arabic portal', () => {
  test.use({ locale: 'ar' })

  test('the drift finding is translated rather than shown as its enum value', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })

    await page.goto('/admin/drift')

    /*
     * The bug this catches is silent: the page falls back to the raw enum
     * value when a kind has no string, so a missing translation renders as
     * `missing_at_provider` on both portals and nothing fails.
     */
    const finding = missingAtProvider(page)
    await expect(finding).toContainText('غير موجود لدى المزوّد')
    await expect(finding).not.toContainText(/missing_at_provider/)
  })
})
