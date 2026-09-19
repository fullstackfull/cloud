import { expect, type Page } from '@playwright/test'

/**
 * What a page's own geometry says about sideways scrolling.
 *
 * `scrollWidth - clientWidth` on the root element is the one measurement that
 * answers "does the whole page scroll sideways", which is the question §19
 * asks. Read from the browser rather than inferred from CSS: a fixed width, a
 * negative margin, an unbreakable string and a table with no scroller all
 * produce the same defect by different routes, and only the geometry sees all
 * four.
 *
 * One pixel of slack, and no more. Sub-pixel layout rounding can leave a
 * fractional difference on a page that is laid out correctly; two pixels is
 * already something a customer can drag.
 */
export async function pageOverflow(page: Page): Promise<number> {
  return page.evaluate(() => {
    const root = document.documentElement

    return root.scrollWidth - root.clientWidth
  })
}

/** An element whose box leaves the viewport with no scroll container holding it. */
export interface Offender {
  where: string
  past: number
  width: number
  text: string
}

/**
 * Which elements cross the viewport edge, and are not held by a scroller.
 *
 * The page measurement above says *whether* a page scrolls sideways; this says
 * *what* is doing it, so a failure names the element instead of leaving
 * somebody to bisect the CSS. An element inside a deliberate scroll container
 * is excluded, because a table that scrolls inside its own box is the design
 * working (§5) rather than a defect.
 *
 * Direction-aware: in Arabic the page overflows to the *left*, so the measure
 * is the distance past the start edge, not past the right edge.
 */
export async function unheldOverflow(page: Page): Promise<Offender[]> {
  return page.evaluate(() => {
    const root = document.documentElement
    const viewport = root.clientWidth
    const rightToLeft = root.dir === 'rtl'
    const found: Offender[] = []

    for (const element of Array.from(document.querySelectorAll('body *'))) {
      const style = getComputedStyle(element)

      // A fixed element is positioned against the viewport and cannot make the
      // document scroll; an invisible one is not on the screen to overflow it.
      if (style.display === 'none' || style.visibility === 'hidden') continue
      if (style.position === 'fixed') continue

      const box = element.getBoundingClientRect()
      if (box.width === 0 || box.height === 0) continue

      const past = rightToLeft ? -box.left : box.right - viewport
      if (past <= 2) continue

      let held = false
      for (let parent = element.parentElement; parent !== null; parent = parent.parentElement) {
        const containing = getComputedStyle(parent).overflowX
        if (containing === 'auto' || containing === 'scroll' || containing === 'hidden' || containing === 'clip') {
          held = true
          break
        }
      }
      if (held) continue

      const classes =
        typeof element.className === 'string' && element.className !== ''
          ? `.${element.className.trim().split(/\s+/).slice(0, 4).join('.')}`
          : ''

      found.push({
        where: element.tagName.toLowerCase() + classes,
        past: Math.round(past),
        width: Math.round(box.width),
        text: element.textContent.trim().slice(0, 50),
      })
    }

    return found.sort((first, second) => second.past - first.past).slice(0, 4)
  })
}

/**
 * Asserts that this screen does not scroll sideways, and says what did if it
 * does.
 *
 * The two measurements are made together on purpose: the assertion that
 * matters is the page one, and the element list exists so that the failure
 * message is actionable rather than a number.
 */
export async function expectNoPageOverflow(page: Page, screen: string): Promise<void> {
  const overflow = await pageOverflow(page)

  if (overflow > 1) {
    const offenders = await unheldOverflow(page)
    const width = page.viewportSize()?.width ?? 0

    /*
     * An empty list is not a missing diagnosis, it is the diagnosis. Every box
     * past the edge is inside a scroll container, so the thing extending the
     * document is escaping one — which in practice means something absolutely
     * positioned inside a `position: static` scroller, since that scroller is
     * not its containing block and therefore does not clip it. That is the
     * defect this helper was written for, and it is worth naming in the
     * failure rather than leaving the reader with a bare number.
     */
    const cause =
      offenders.length > 0
        ? `Past the edge: ${offenders
            .map((each) => `${each.where} (+${each.past.toString()}px, "${each.text}")`)
            .join('; ')}`
        : 'Nothing visible is past the edge, so something is escaping a scroll container — look ' +
          'for an absolutely positioned descendant (sr-only text, a tooltip) inside a scroller ' +
          'that is `position: static` and therefore not its containing block.'

    expect(
      overflow,
      `${screen} scrolls the whole page sideways at ${width.toString()}px by ` +
        `${overflow.toString()}px. ${cause}`,
    ).toBeLessThanOrEqual(1)
  }

  expect(overflow, `${screen} scrolls the whole page sideways`).toBeLessThanOrEqual(1)
}
