import AxeBuilder from '@axe-core/playwright'
import { expect, type Page } from '@playwright/test'

/**
 * Automated accessibility scanning, as a floor rather than a verdict.
 *
 * ## What this can and cannot tell us
 *
 * axe finds machine-checkable failures: a control with no accessible name, an
 * input with no label, contrast below the threshold, a duplicated landmark, an
 * ARIA attribute on an element that cannot carry it. Those are real defects
 * and finding them by running is far better than finding them by reading.
 *
 * It cannot tell whether the name a control has is a *useful* name, whether
 * the tab order matches the reading order, whether focus goes somewhere
 * sensible after a dialogue closes, or whether an Arabic screen reader makes
 * sense of a concatenated string. Those are the manual journeys, and they are
 * where every interesting defect in this wave was found.
 *
 * So: a clean axe run is **not** a claim of conformance, and nothing in this
 * repository claims WCAG certification. It is a regression net under the
 * manual work.
 *
 * ## Why dev-only, and why here
 *
 * `@axe-core/playwright` is a devDependency and is imported only from this
 * file, which only the browser suite loads. Nothing in `src/` imports it, so
 * it cannot reach a production bundle — the accessibility of the product must
 * not depend on a library shipped to customers to measure it.
 */

/** The rule tags scanned: the WCAG 2.1 A and AA rules, plus axe's own set. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice']

/**
 * Violations axe reports that are not defects in this product, with the reason.
 *
 * Deliberately tiny. An exclusion list is how an automated check stops
 * checking, so each entry has to be a statement somebody can disagree with.
 */
const NOT_A_DEFECT: Record<string, string> = {
  /*
   * axe wants every page's content inside a landmark. The `<dialog>` elements
   * this portal uses for confirmations are top-layer siblings of the page
   * shell, so their content is outside `<main>` by construction — which is
   * what the dialog role is for, and what makes the page behind inert.
   */
  'region': 'native <dialog> content is a top-layer sibling of <main> by design',
}

export interface Violation {
  id: string
  impact: string
  help: string
  nodes: number
  targets: string[]
}

/**
 * Every violation on the current page, at serious or critical impact.
 *
 * Minor and moderate findings are returned too, separately, because the wave
 * treats serious and critical as blockers and the rest as recorded facts.
 */
export async function scan(page: Page): Promise<{ blocking: Violation[]; other: Violation[] }> {
  const results = await new AxeBuilder({ page }).withTags(TAGS).analyze()

  const all: Violation[] = results.violations
    .filter((violation) => NOT_A_DEFECT[violation.id] === undefined)
    .map((violation) => ({
      id: violation.id,
      impact: violation.impact ?? 'unknown',
      help: violation.help,
      nodes: violation.nodes.length,
      targets: violation.nodes.slice(0, 3).map((node) => node.target.join(' ')),
    }))

  return {
    blocking: all.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical'),
    other: all.filter((violation) => violation.impact !== 'serious' && violation.impact !== 'critical'),
  }
}

/** Scans, prints everything it found, and fails on serious or critical. */
export async function expectAccessible(page: Page, where: string): Promise<void> {
  const { blocking, other } = await scan(page)

  for (const violation of other) {
    // Recorded, not failed: a moderate finding is a fact for the report.
    console.log(`axe ${where}: ${violation.impact} ${violation.id} (${String(violation.nodes)}) — ${violation.help}`)
  }

  expect(
    blocking.map((violation) => `${violation.id} [${violation.impact}] ${violation.help} :: ${violation.targets.join(' | ')}`),
    `Serious or critical accessibility violations on ${where}`,
  ).toEqual([])
}
