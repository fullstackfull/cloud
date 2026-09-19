import { expect, test, type Page } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * A zone in and out as a file, end to end.
 *
 * What the browser is needed for: the whole feature is a promise that what
 * the customer saw in the preview is what gets written, and nothing else —
 * a refused line applies nothing, a replace says what it removes before it
 * removes it, an export carries the records and no identifiers. Each spec
 * claims and gives up its own domain, so the fixtures are left as found.
 */

const DOMAIN = 'e2e-zonefile.test'

// Each spec claims a domain, imports against it, reads it back and gives it
// up: several round trips more than a single screen's worth.
test.describe.configure({ timeout: 90_000 })

/** Moves to one section of the zone's page. The sections are links, not tabs. */
async function section(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('navigation', { name: /sections/i }).getByRole('link', { name }).click()
}

/**
 * Claims the domain and opens the import section of its own page.
 *
 * Since Wave 3 the zone has an address of its own, so a run that failed
 * halfway leaves a link on the index rather than an option in a select.
 */
async function claim(page: Page): Promise<void> {
  await page.goto('/dns')

  // A run that failed halfway leaves the domain behind; start from nothing.
  const leftover = page.getByRole('link', { name: DOMAIN })
  if ((await leftover.count()) > 0) {
    await leftover.first().click()
    await giveUp(page)
    await page.goto('/dns')
  }

  await page.getByLabel(/^domain$/i).first().fill(DOMAIN)
  await page.getByRole('button', { name: /add domain/i }).click()

  await page.getByRole('link', { name: DOMAIN }).first().click()
  await expect(page.getByRole('heading', { level: 1, name: DOMAIN })).toBeVisible()

  await section(page, /^import and export$/i)
}

async function giveUp(page: Page): Promise<void> {
  await section(page, /^danger zone$/i)
  await page.getByRole('button', { name: /give up domain/i }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox').fill(DOMAIN)
  await dialog.getByRole('button', { name: /give up domain/i }).click()
  await expect(page.getByRole('dialog')).toBeHidden()
}

async function preview(page: Page, text: string): Promise<void> {
  await page.getByLabel(/or paste the zone text/i).fill(text)
  await page.getByRole('button', { name: /preview changes/i }).click()
  await expect(page.getByTestId('zone-import-plan')).toBeVisible()
}

async function applyPlan(page: Page): Promise<void> {
  await page.getByRole('button', { name: /apply this plan/i }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox').fill(DOMAIN)
  await dialog.getByRole('button', { name: /apply this plan/i }).click()
  await expect(page.getByText(/^imported:/i)).toBeVisible()
}

test.describe('in English', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  test('a zone file is previewed as a diff, applied through the same checks, and exported back without identifiers', async ({
    page,
  }) => {
    await claim(page)

    await preview(
      page,
      [
        '$ORIGIN ' + DOMAIN + '.',
        '$TTL 3600',
        '@ IN SOA a.ns.fake.test. hostmaster ( 1 3600 900 604800 300 )',
        '@ IN NS a.ns.fake.test.',
        'www IN A 203.0.113.10',
        'api 300 IN A 203.0.113.20',
        '@ IN MX 10 mail',
        '@ IN TXT "v=spf1 mx -all"',
      ].join('\n'),
    )

    const plan = page.getByTestId('zone-import-plan')
    // The SOA and apex NS are listed as ignored — not silently dropped.
    await expect(plan.getByText(/ignored/i).first()).toBeVisible()
    await expect(plan.getByText('4 add')).toBeVisible()

    await applyPlan(page)

    // The records are in the zone, published like any other — read in the
    // section that lists them, which is where they live now.
    await section(page, /^records$/i)
    const records = page.getByRole('table', { name: /^records$/i })
    await expect(records.getByRole('row').filter({ hasText: 'api.' + DOMAIN })).toBeVisible()
    await expect(records.getByRole('row').filter({ hasText: 'mail.' + DOMAIN })).toBeVisible()

    // Previewing the same records again changes nothing — including the TTLs,
    // which are part of a record and not decoration.
    await section(page, /^import and export$/i)
    await preview(page, 'www 3600 IN A 203.0.113.10\napi 300 IN A 203.0.113.20\n')
    await expect(page.getByTestId('zone-import-plan').getByText('0 add')).toBeVisible()
    await expect(page.getByTestId('zone-import-plan').getByText('0 change')).toBeVisible()
    await expect(page.getByRole('button', { name: /apply this plan/i })).toBeDisabled()

    // Out again as a file: the records, the origin, and none of the ids.
    await page.getByRole('button', { name: /export zone file/i }).click()
    const exported = page.getByTestId('zone-export')
    await expect(exported).toContainText('$ORIGIN ' + DOMAIN + '.')
    await expect(exported).toContainText('api 300 IN A 203.0.113.20')
    await expect(exported).toContainText('@ 3600 IN MX 10 mail.' + DOMAIN + '.')
    await expect(exported).not.toContainText('01J')
    await expect(page.getByRole('link', { name: /download/i })).toHaveAttribute('download', DOMAIN + '.zone')

    await giveUp(page)
  })

  test('one refused line refuses the whole file, with the line and the reason', async ({ page }) => {
    await claim(page)

    await preview(page, 'www IN A 203.0.113.10\n$INCLUDE /etc/passwd\ninternal IN A 10.0.0.5\n')

    const plan = page.getByTestId('zone-import-plan')
    await expect(plan.getByText(/nothing will be applied while any line is refused/i)).toBeVisible()
    await expect(plan.getByText(/names a file on somebody/i)).toBeVisible()
    await expect(plan.getByText('2 refused')).toBeVisible()
    await expect(page.getByRole('button', { name: /apply this plan/i })).toBeDisabled()

    // And the good line was not written on the side.
    await section(page, /^records$/i)
    const records = page.getByRole('table', { name: /^records$/i })
    await expect(records.getByRole('row').filter({ hasText: 'www.' + DOMAIN })).toHaveCount(0)

    await giveUp(page)
  })

  test('replace says what it removes before it removes it; merge never removes', async ({ page }) => {
    await claim(page)

    const records = page.getByRole('table', { name: /^records$/i })

    // The record this spec is about, added by hand in the records section.
    await section(page, /^records$/i)
    await page.getByLabel(/name \(blank for/i).fill('old')
    await page.getByLabel(/^value$/i).fill('203.0.113.99')
    await page.getByRole('button', { name: /add record/i }).click()
    await expect(records.getByRole('row').filter({ hasText: 'old.' + DOMAIN })).toBeVisible()

    // Merge: the record the file does not mention is kept, and the plan says so.
    await section(page, /^import and export$/i)
    await preview(page, 'www IN A 203.0.113.10\n')
    await expect(page.getByTestId('zone-import-plan').getByText('0 remove')).toBeVisible()
    await expect(page.getByTestId('zone-import-plan').getByText('1 kept as they are')).toBeVisible()

    // Replace: the same file now removes it, and the plan lists the removal
    // before anything happens.
    await page.getByRole('combobox', { name: /^mode$/i }).selectOption('replace')
    await page.getByRole('button', { name: /preview changes/i }).click()
    const plan = page.getByTestId('zone-import-plan')
    await expect(plan.getByText('1 remove')).toBeVisible()
    await expect(plan.getByRole('row').filter({ hasText: 'old.' + DOMAIN })).toBeVisible()

    await applyPlan(page)

    await section(page, /^records$/i)
    await expect(records.getByRole('row').filter({ hasText: 'old.' + DOMAIN })).toHaveCount(0)
    await expect(records.getByRole('row').filter({ hasText: 'www.' + DOMAIN })).toBeVisible()

    await giveUp(page)
  })
})

test.describe('Arabic', () => {
  test.use({ locale: 'ar' })

  test('the import reads in Arabic and keeps the zone text left-to-right', async ({ page }) => {
    await signIn(page, users.customer, { headingPattern: /مرحب|أهل/ })

    await page.goto('/dns')
    await page.getByLabel('النطاق').first().fill(DOMAIN)
    await page.getByRole('button', { name: 'إضافة نطاق' }).click()

    await page.getByRole('link', { name: DOMAIN }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: DOMAIN })).toBeVisible()

    await page
      .getByRole('navigation', { name: 'الأقسام' })
      .getByRole('link', { name: 'الاستيراد والتصدير' })
      .click()

    const textarea = page.getByLabel('أو الصق نص المنطقة')
    expect(await textarea.evaluate((node) => getComputedStyle(node).direction)).toBe('ltr')

    await textarea.fill('www IN A 203.0.113.10\n')
    await page.getByRole('button', { name: 'معاينة التغييرات' }).click()
    await expect(page.getByTestId('zone-import-plan').getByText('إضافة').first()).toBeVisible()

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
