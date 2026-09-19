import { expect, test } from '@playwright/test'

import { signIn, users } from './support/helpers'

/*
 * §18. The dark-theme path, as regression safety and nothing more.
 *
 * ## The correction this file exists to hold
 *
 * A dark-theme path already existed before Wave 5, through the operating
 * system's preference and through a `data-theme` stamp. **Wave 5 did not add
 * dark mode.** What Wave 5 did was repair the semantic-token consistency of
 * the path it found: it added the twelve `--tone-*` tokens to all three theme
 * states, and it fixed `--danger-surface` and `--danger-surface-strong`, which
 * had been defined in `:root` and in the media query but not in the stamp.
 *
 * W5.8 must not turn that into a product feature, and must not add a toggle.
 * What it must do is not knowingly break the path while moving layout about —
 * so this spec spot-checks that the theme still resolves on representative
 * screens, and stops there.
 *
 * ## What it asserts, and why by measurement
 *
 * That the page is actually dark, and that text is readable on it. A token
 * that stops resolving does not warn: it renders as nothing and the element
 * keeps whatever it inherited, which is how light-theme text ends up on a dark
 * ground. `every-colour-has-a-name.test.ts` asserts the tokens are *defined*
 * in all three states; this asserts the browser *resolves* them, which is the
 * half a source test cannot see.
 *
 * The emulation is `prefers-color-scheme: dark` rather than a `data-theme`
 * stamp, because nothing in the portal writes that attribute: the system
 * preference is the only route a customer has, so it is the route worth
 * testing.
 */

/**
 * How light a colour is, 0 (black) to 1 (white).
 *
 * Two notations, because the browser hands back whichever the value was
 * authored in: these tokens are written in `oklch()` and Chromium serialises
 * them that way rather than converting to `rgb()`. The first component of
 * `oklch()` *is* perceptual lightness on exactly this scale, so it is read
 * directly; an `rgb()` value gets the usual relative-luminance weighting.
 *
 * Written down because the first version of this helper only understood
 * `rgb()`, returned `NaN` for every token in the design system, and failed
 * with "the page is not dark" on a page that was correctly dark. A colour
 * parser that silently yields NaN fails in the direction that looks like a
 * product defect.
 */
function lightness(colour: string): number {
  const oklch = /^oklch\(\s*([0-9.]+)/.exec(colour)

  if (oklch !== null) return Number.parseFloat(oklch[1] ?? '0')

  const [red = 0, green = 0, blue = 0] = (/rgba?\(([^)]+)\)/.exec(colour)?.[1] ?? '0,0,0')
    .split(',')
    .map((part) => Number.parseFloat(part))

  return (0.2126 * red + 0.7152 * green + 0.0722 * blue) / 255
}

test.describe('the dark theme that was already here', () => {
  test.describe.configure({ timeout: 300_000 })

  test('paints a dark ground with light text on it', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await signIn(page, users.customer)

    for (const address of ['/', '/services', '/invoices', '/security', '/settings/team']) {
      await page.goto(address)
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
      await page.waitForTimeout(600)

      const measured = await page.evaluate(() => {
        const heading = document.querySelector('main h1')

        return {
          ground: getComputedStyle(document.documentElement).backgroundColor,
          text: heading === null ? '' : getComputedStyle(heading).color,
        }
      })

      expect(lightness(measured.ground), `${address} is not dark`).toBeLessThan(0.3)
      expect(lightness(measured.text), `${address} has dark text on a dark ground`)
        .toBeGreaterThan(0.6)
    }
  })

  test('still resolves every tinted surface, rather than rendering it as nothing', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await signIn(page, users.customer)
    await page.goto('/')

    /*
     * The twelve `--tone-*` tokens and the four danger tokens, resolved in the
     * browser in this theme. An undefined custom property resolves to the
     * empty string, which is exactly the failure Wave 5 found and fixed in two
     * of them — so "not empty" is the assertion that matters.
     */
    const unresolved = await page.evaluate(() => {
      const names = [
        '--surface', '--surface-raised', '--surface-sunken', '--surface-base',
        '--border', '--border-subtle', '--border-strong',
        '--text-primary', '--text-secondary', '--text-muted',
        '--danger-text', '--warning-text', '--danger-border',
        '--danger-surface', '--danger-surface-strong', '--accent',
        '--tone-danger-surface', '--tone-danger-border', '--tone-danger-text',
        '--tone-warning-surface', '--tone-warning-border', '--tone-warning-text',
        '--tone-info-surface', '--tone-info-border', '--tone-info-text',
        '--tone-success-surface', '--tone-success-border', '--tone-success-text',
      ]
      const style = getComputedStyle(document.documentElement)

      return names.filter((name) => style.getPropertyValue(name).trim() === '')
    })

    expect(unresolved, 'these resolve to nothing and the element keeps what it inherited')
      .toEqual([])
  })

  test('does not quietly fall back to the light theme for a token it forgot', async ({ page }) => {
    /*
     * The exact Wave 5 defect, from the only angle that sees it.
     *
     * `--danger-surface` was defined in `:root` and in the media query and
     * *not* in the `data-theme` stamp. A token in that state does not resolve
     * to nothing — it resolves to the light theme's value — so a check that
     * only asks "is this non-empty" passes while a customer in dark mode reads
     * every destructive button in the light theme's red. The first version of
     * this spec made exactly that mistake and passed when the token was
     * removed.
     *
     * So the assertion is a comparison: every theme-dependent token must
     * differ between the two themes. If a dark block forgets one, the value
     * falls through to `:root` and the two readings come back identical.
     */
    await signIn(page, users.customer)
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()

    const THEMED = [
      '--surface', '--surface-raised', '--surface-sunken', '--surface-base',
      '--border', '--border-subtle', '--border-strong',
      '--text-primary', '--text-secondary', '--text-muted',
      '--danger-text', '--warning-text', '--danger-border',
      '--danger-surface', '--danger-surface-strong', '--accent',
      '--tone-danger-surface', '--tone-danger-border', '--tone-danger-text',
      '--tone-warning-surface', '--tone-warning-border', '--tone-warning-text',
      '--tone-info-surface', '--tone-info-border', '--tone-info-text',
      '--tone-success-surface', '--tone-success-border', '--tone-success-text',
    ]

    const read = async (): Promise<Record<string, string>> =>
      page.evaluate((names: string[]) => {
        const style = getComputedStyle(document.documentElement)
        const values: Record<string, string> = {}
        for (const name of names) values[name] = style.getPropertyValue(name).trim()

        return values
      }, THEMED)

    await page.emulateMedia({ colorScheme: 'light' })
    const light = await read()

    await page.emulateMedia({ colorScheme: 'dark' })
    const dark = await read()

    const shared = THEMED.filter((name) => light[name] === dark[name])

    expect(
      shared,
      'These read the same in both themes, which means a dark block is missing them and the ' +
        'light value is falling through from `:root`.',
    ).toEqual([])
  })

  test('keeps a destructive control filled in the dark theme', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await signIn(page, users.customer)
    await page.goto('/vps')
    await page.locator('main').getByRole('link', { name: 'e2e-app-02', exact: true }).click()
    await page.goto(`${new URL(page.url()).pathname}/danger`)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    /*
     * And the control is filled at all. If the token stopped resolving
     * entirely the button would be transparent, which the comparison above
     * would not notice — an empty string is equal to an empty string.
     */
    const fill = await page
      .getByRole('button')
      .filter({ hasText: /reinstall|terminate|destroy/i })
      .first()
      .evaluate((element) => getComputedStyle(element).backgroundColor)

    const ground = await page.evaluate(
      () => getComputedStyle(document.documentElement).backgroundColor,
    )

    expect(fill, 'a destructive control has no fill of its own in the dark theme').not.toBe(ground)
    expect(fill).not.toBe('rgba(0, 0, 0, 0)')
  })
})
