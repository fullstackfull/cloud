import { expect, test, type Page } from '@playwright/test'

import { fixtures, signIn, users } from '../support/helpers'
import { openMenu } from '../support/mobile'

/*
 * W5.6 item 12. How big the things you tap actually are, measured.
 *
 * Measured in the browser, on the phone descriptor, from
 * `getBoundingClientRect()` — not read off the stylesheet. The reason is that
 * the stylesheet cannot answer the question: a control's hit area is the
 * outcome of its padding, its line height, the font that actually loaded, its
 * `min-height`, whatever flex container it sits in and whether an ancestor
 * clipped it. A class list that looks generous can render 18 pixels tall, and
 * the audit's guess from the CSS was wrong in both directions.
 *
 * The floor here is 24 × 24 CSS pixels — the minimum WCAG 2.2 SC 2.5.8
 * describes. That is a measurement against a published number and nothing
 * more: this file does not test the spacing exception the same criterion
 * allows, so it is neither a conformance claim nor a certification, and
 * nothing in Wave 5 claims one.
 *
 * Anything under the floor is either fixed or listed in `ALLOWED` with a
 * written reason. The list is checked for staleness, because an exemption
 * nobody can remember the reason for is how a floor stops being one.
 */

/** The floor, in CSS pixels. */
const FLOOR = 24

/**
 * Controls measured under the floor, each with the reason it is allowed.
 *
 * Keyed by the accessible name as measured. Empty is the goal; an entry is a
 * decision, not a to-do.
 */
const ALLOWED: Record<string, string> = {}

interface Measured {
  name: string
  role: string
  width: number
  height: number
}

/**
 * Every interactive thing on screen, with the size it is drawn at.
 *
 * Visible only: a control in a closed drawer or behind a `hidden` attribute is
 * not a target anybody can miss. Size comes from the layout box rather than
 * from any declared property, which is the whole point.
 */
async function measureTargets(page: Page): Promise<Measured[]> {
  return page.evaluate(() => {
    const selector = [
      'a[href]',
      'button',
      'input:not([type="hidden"])',
      'select',
      'textarea',
      'summary',
      '[role="button"]',
      '[role="switch"]',
      '[role="link"]',
      '[role="tab"]',
      '[role="checkbox"]',
    ].join(', ')

    const named = (element: Element): string => {
      const label = element.getAttribute('aria-label')

      if (label !== null && label.trim() !== '') return label.trim()

      const by = element.getAttribute('aria-labelledby')

      if (by !== null) {
        const target = document.getElementById(by)

        if (target !== null) return target.textContent.trim()
      }

      const own = element.textContent.replace(/\s+/g, ' ').trim()

      if (own !== '') return own.slice(0, 60)

      // A file or text input takes its name from its `<label>`.
      const id = element.getAttribute('id')

      if (id !== null) {
        const label = document.querySelector(`label[for="${id}"]`)

        if (label !== null) return label.textContent.replace(/\s+/g, ' ').trim().slice(0, 60)
      }

      return `<${element.tagName.toLowerCase()}> with no name`
    }

    return Array.from(document.querySelectorAll(selector))
      .filter((element) => {
        if (element.closest('[aria-hidden="true"]') !== null) return false
        if (element.hasAttribute('hidden')) return false

        const style = window.getComputedStyle(element)

        if (style.display === 'none' || style.visibility === 'hidden') return false

        const box = element.getBoundingClientRect()

        // Zero in either direction means it is not rendered at all — a skip
        // link parked off-screen, an input inside a collapsed section.
        return box.width > 0 && box.height > 0
      })
      .map((element) => {
        const box = element.getBoundingClientRect()

        return {
          name: named(element),
          role: element.getAttribute('role') ?? element.tagName.toLowerCase(),
          width: Math.round(box.width * 10) / 10,
          height: Math.round(box.height * 10) / 10,
        }
      })
  })
}

function tooSmall(targets: Measured[]): Measured[] {
  return targets.filter(
    (target) =>
      (target.width < FLOOR || target.height < FLOOR) && ALLOWED[target.name] === undefined,
  )
}

function describe(targets: Measured[]): string {
  return targets
    .map((t) => `  ${t.role} "${t.name}" — ${String(t.width)} × ${String(t.height)}`)
    .join('\n')
}

/** Screens worth measuring, and how to reach each on a phone. */
const SCREENS: Array<{ what: string; open: (page: Page) => Promise<void> }> = [
  { what: 'the dashboard', open: async (page) => { await page.goto('/') } },
  { what: 'the machines list', open: async (page) => { await page.goto('/vps') } },
  {
    what: 'a machine, with its actions',
    open: async (page) => {
      await page.goto('/vps')
      await page.getByRole('link', { name: fixtures.operableHostname, exact: true }).first().click()
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    },
  },
  { what: 'the invoices list', open: async (page) => { await page.goto('/invoices') } },
  { what: 'the DNS screen', open: async (page) => { await page.goto('/dns') } },
  { what: 'the team screen', open: async (page) => { await page.goto('/settings/team') } },
  { what: 'the security page', open: async (page) => { await page.goto('/security') } },
  { what: 'the API tokens page', open: async (page) => { await page.goto('/api-tokens') } },
  { what: 'support', open: async (page) => { await page.goto('/support') } },
  { what: 'the profile, with its switches', open: async (page) => { await page.goto('/profile') } },
]

test.describe('what a finger has to hit', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, users.customer)
  })

  for (const screen of SCREENS) {
    test(`every control on ${screen.what} is at least 24 by 24`, async ({ page }) => {
      await screen.open(page)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

      const targets = await measureTargets(page)

      // A screen that measured nothing measured nothing, which is not a pass.
      expect(targets.length, 'controls were found to measure').toBeGreaterThan(0)

      const small = tooSmall(targets)

      expect(small, `under ${String(FLOOR)}px on ${screen.what}:\n${describe(small)}`).toEqual([])
    })
  }

  test('the drawer, open, is a column of full-width destinations', async ({ page }) => {
    /*
     * The navigation is the one surface where a small target is not one
     * mistake but twenty-one in a column, each one next to the wrong page.
     */
    await page.goto('/')
    await openMenu(page)

    const targets = await measureTargets(page)
    const small = tooSmall(targets)

    expect(small, `under ${String(FLOOR)}px in the open drawer:\n${describe(small)}`).toEqual([])
  })

  test('the exemption list carries no stale entries', async ({ page }) => {
    /*
     * An allow-list nobody prunes stops being a list of decisions and becomes
     * a list of things that used to be true. If a name here is no longer
     * measured under the floor anywhere, the entry goes.
     */
    const names = Object.keys(ALLOWED)

    if (names.length === 0) {
      // Nothing is exempt, which is the state this list is meant to be kept in.
      expect(names).toEqual([])

      return
    }

    const seen = new Set<string>()

    for (const screen of SCREENS) {
      await screen.open(page)

      for (const target of await measureTargets(page)) {
        if (target.width < FLOOR || target.height < FLOOR) seen.add(target.name)
      }
    }

    expect(
      names.filter((name) => ! seen.has(name)),
      'exemptions for controls that now measure large enough',
    ).toEqual([])
  })
})
