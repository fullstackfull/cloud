import { expect, test } from '@playwright/test'

import { fixtures, signIn, users } from './support/helpers'

/*
 * The overview and the site registry: the estate on one screen with what
 * needs a person first, and the places machines can be.
 */

test.describe('an operator opening the control centre', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.operator)
  })

  test('the overview counts the estate and links what needs a person', async ({ page }) => {
    await page.goto('/admin/control-center')
    await expect(page.getByRole('heading', { name: /^infrastructure overview$/i })).toBeVisible()

    // The seeded estate has machines nobody classified by decision and a
    // credential the controller does not hold: both are on the list.
    const attention = page.getByRole('list', { name: /needs a person/i })
    await expect(attention.getByText(/credential\(s\) not on the controller/i)).toBeVisible()
    await expect(attention.getByText(/machine\(s\) never classified/i)).toBeVisible()

    await expect(page.getByRole('link', { name: /^machines$/i })).toBeVisible()
    await expect(page.getByRole('link', { name: /^datacenters$/i })).toBeVisible()

    await attention.getByText(/credential\(s\) not on the controller/i).click()
    await expect(page.getByRole('heading', { name: /^credentials$/i })).toBeVisible()
  })

  test('registers a rack, and is refused a second with the same name', async ({ page }) => {
    await page.goto('/admin/control-center/sites')
    await expect(page.getByRole('heading', { name: /^sites$/i })).toBeVisible()

    // `exact` matters: the rack registered below is named from the clock and
    // can begin with the fixture's name (E2E-R1PEL once did, on CI).
    const datacenter = page.getByRole('listitem', { name: fixtures.datacenter, exact: true })
    await expect(datacenter.getByRole('listitem', { name: fixtures.rack, exact: true })).toBeVisible()
    await expect(datacenter).toContainText(/PDU-1/)

    const name = `E2E-R${Date.now().toString(36).slice(-4).toUpperCase()}`
    await page.getByRole('button', { name: /register a rack/i }).click()
    const form = page.getByRole('form', { name: /register a rack/i })
    await form.getByLabel(/^datacenter$/i).selectOption({ label: fixtures.datacenter })
    await form.getByLabel(/rack name/i).fill(name)
    await form.getByLabel(/^row$/i).fill('B')
    await form.getByLabel(/power notes/i).fill('PDU-2')
    await form.getByRole('button', { name: /register a rack/i }).click()

    await expect(datacenter.getByRole('listitem', { name })).toBeVisible()
    await expect(datacenter.getByRole('listitem', { name })).toContainText(/PDU-2/)

    // The same name again: refused by the platform, said on the form.
    await page.getByRole('button', { name: /register a rack/i }).click()
    const again = page.getByRole('form', { name: /register a rack/i })
    await again.getByLabel(/^datacenter$/i).selectOption({ label: fixtures.datacenter })
    await again.getByLabel(/rack name/i).fill(fixtures.rack)
    await again.getByRole('button', { name: /register a rack/i }).click()
    await expect(again.getByText(/already has a rack named/i)).toBeVisible()
  })
})

test.describe('in Arabic', () => {
  test.use({ locale: 'ar' })

  test('the overview and sites screens read in Arabic', async ({ page }) => {
    await signIn(page, users.operator, { headingPattern: /مرحب|أهل/ })
    await page.goto('/admin/control-center')
    await expect(page.getByRole('heading', { name: /نظرة عامة على البنية التحتية/ })).toBeVisible()
    await expect(page.getByText(/يحتاج إلى شخص/)).toBeVisible()

    await page.goto('/admin/control-center/sites')
    await expect(page.getByRole('heading', { name: /المواقع/ })).toBeVisible()
    const datacenter = page.getByRole('listitem', { name: fixtures.datacenter, exact: true })
    await expect(datacenter.getByRole('listitem', { name: fixtures.rack, exact: true })).toBeVisible()
  })
})
