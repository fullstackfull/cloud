import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The discovery screen exists to make "nobody has checked" visible. So the
 * assertions are about the gaps: a provider nobody has asked, a machine
 * nobody has looked at, each said in words rather than left as an empty
 * cell.
 *
 * Only fixtures no other spec mutates are asserted on, so this spec is
 * indifferent to the order the suite runs in.
 */

test.describe('an operator reading discovery', () => {
  test('sees which providers and machines have never been asked', async ({ page }) => {
    await signIn(page, users.operator)
    await page.goto('/admin/control-center/discovery')
    await expect(page.getByRole('heading', { name: /^discovery$/i })).toBeVisible()

    const blocked = page.getByRole('listitem', { name: fixtures.providerBlocked })
    await expect(blocked).toContainText(/never asked/i)
    await expect(blocked).toContainText(/nothing observed/i)

    const untouched = page.getByRole('listitem', { name: fixtures.machineUntouched })
    await expect(untouched).toContainText(/never looked at/i)
    await expect(untouched).toContainText(/do not touch/i)

    // Nothing here is editable: the screen offers no buttons at all.
    await expect(page.getByRole('main').getByRole('button')).toHaveCount(0)
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the discovery screen reads in Arabic', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center/discovery')

    await expect(page.getByRole('heading', { name: /الاستكشاف/ })).toBeVisible()
    await expect(page.getByRole('listitem', { name: fixtures.machineUntouched })).toContainText(/لم يُفحص قط/)
    await expect(page.getByRole('listitem', { name: fixtures.providerBlocked })).toContainText(/لم يُسأل قط/)
  })
})
