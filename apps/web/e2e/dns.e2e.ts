import { expect, test, type Page } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * Forward DNS, end to end.
 *
 * The reason this needs a browser and a real API: the whole feature is a chain
 * of claims about the world — the zone was created, these are the nameservers,
 * this record is live — and each link is a place where a screen could say
 * something the platform has not established. What the suite checks is that
 * the screen tells the truth at each step, including the step where the truth
 * is "this does nothing until you delegate the domain".
 *
 * The domain is claimed and given up within each spec, so the fixtures are
 * left as they were found.
 */

const DOMAIN = 'e2e-dns.test'

/**
 * Claims a domain and opens its own page.
 *
 * Since Wave 3 the index claims and lists; everything about one zone — the
 * delegation, the records, the import, giving it up — is on `/dns/{name}`,
 * which is the address a customer keeps.
 */
async function claim(page: Page, domain: string = DOMAIN): Promise<void> {
  await page.goto('/dns')
  await page.getByLabel(/^domain$/i).first().fill(domain)
  await page.getByRole('button', { name: /add domain/i }).click()

  await page.getByRole('link', { name: domain }).first().click()
  await expect(page.getByRole('heading', { level: 1, name: domain })).toBeVisible()
}

/** Moves to one section of the zone's page. The sections are links, not tabs. */
async function section(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name }).click()
}

async function giveUp(page: Page, domain: string = DOMAIN): Promise<void> {
  await section(page, /^danger zone$/i)
  await page.getByRole('button', { name: /give up domain/i }).click()

  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox').fill(domain)
  await dialog.getByRole('button', { name: /give up domain/i }).click()
  await expect(page.getByRole('dialog')).toBeHidden()
}

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('a claimed domain says what has to happen at the registrar before it serves anything', async ({
    page,
  }) => {
    await claim(page)

    // The nameservers are the product. Without them the customer has nothing
    // to type into their registrar and the zone serves nobody.
    await expect(page.getByText(/\.ns\.fake\.test/).first()).toBeVisible()

    // And the sentence that stops a green badge being read as "your domain is
    // working". The platform cannot check who owns a domain and says so.
    await expect(page.getByText(/does not check who owns a domain/i)).toBeVisible()

    await giveUp(page)
  })

  test('a record can be published and removed, and the zone shows what the platform believes', async ({
    page,
  }) => {
    await claim(page)
    await section(page, /^records$/i)

    await page.getByLabel(/name \(blank for/i).fill('www')
    await page.getByLabel(/^value$/i).fill('203.0.113.10')
    await page.getByRole('button', { name: /add record/i }).click()

    const row = page.getByRole('row').filter({ hasText: 'www.' + DOMAIN })
    await expect(row).toBeVisible()
    await expect(row.getByText('203.0.113.10')).toBeVisible()

    await row.getByRole('button', { name: /^remove$/i }).click()

    // Removal asks first, naming the record, and backing out changes nothing.
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText('www.' + DOMAIN)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(page.getByRole('row').filter({ hasText: 'www.' + DOMAIN })).toHaveCount(1)

    await row.getByRole('button', { name: /^remove$/i }).click()
    await page.getByRole('dialog').getByRole('button', { name: /^remove record$/i }).click()
    await expect(page.getByRole('row').filter({ hasText: 'www.' + DOMAIN })).toHaveCount(0)

    await giveUp(page)
  })

  test('a value the zone cannot serve is refused, in words the customer can act on', async ({
    page,
  }) => {
    await claim(page)
    await section(page, /^records$/i)

    /*
     * An address inside the customer's own network. A name resolving to it
     * resolves to whatever is at that address on the *visitor's* network,
     * which is the mechanism DNS rebinding is built on — and the customer
     * meant their server.
     */
    await page.getByLabel(/name \(blank for/i).fill('internal')
    await page.getByLabel(/^value$/i).fill('10.0.0.5')
    await page.getByRole('button', { name: /add record/i }).click()

    await expect(page.getByRole('alert').first()).toBeVisible()
    await expect(page.getByRole('row').filter({ hasText: 'internal.' + DOMAIN })).toHaveCount(0)

    await giveUp(page)
  })

  test('giving a domain up cannot be confirmed without typing it back', async ({ page }) => {
    await claim(page)
    await section(page, /^danger zone$/i)

    await page.getByRole('button', { name: /give up domain/i }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/stops resolving/i)).toBeVisible()

    const confirm = dialog.getByRole('button', { name: /give up domain/i })
    await expect(confirm).toBeDisabled()

    // Nearly right is still wrong: the server compares it the same way.
    await dialog.getByRole('textbox').fill(DOMAIN.toUpperCase() + 'x')
    await expect(confirm).toBeDisabled()

    await dialog.getByRole('textbox').fill(DOMAIN)
    await expect(confirm).toBeEnabled()

    await confirm.click()
    await expect(page.getByRole('dialog')).toBeHidden()

    /*
     * Back to the index — the zone this page was about no longer exists —
     * and gone from the list, so the name is free to be claimed again.
     */
    await expect(page).toHaveURL(/\/dns$/)
    await expect(page.getByRole('link', { name: DOMAIN })).toHaveCount(0)
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the DNS screen reads in Arabic and keeps domain names unmirrored', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await page.goto('/dns')
    await page.getByLabel('النطاق').first().fill(DOMAIN)
    await page.getByRole('button', { name: 'إضافة نطاق' }).click()

    await page.getByRole('link', { name: DOMAIN }).first().click()

    const heading = page.getByRole('heading', { level: 1, name: DOMAIN })
    await expect(heading).toBeVisible()

    /*
     * A domain name is technical and must not be mirrored: reversed, it is a
     * different string to anybody reading it back to support. The heading is
     * Arabic-direction prose; the name inside it declares its own direction,
     * which is what the bidirectional algorithm needs.
     */
    const name = heading.locator('[dir="ltr"]')
    await expect(name).toHaveText(DOMAIN)
    expect(await name.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    // The same warning, in Arabic: the platform does not verify ownership.
    await expect(page.getByText(/لا تتحقق من ملكية النطاق/)).toBeVisible()

    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'منطقة الخطر' })
      .click()
    await page.getByRole('button', { name: 'التخلّي عن النطاق' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox').fill(DOMAIN)
    await dialog.getByRole('button', { name: 'التخلّي عن النطاق' }).click()
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})
