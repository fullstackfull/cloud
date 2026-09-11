import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §56. One place decides when the portal asks again.
 *
 * The failure this gate exists for is not hypothetical and not subtle: a
 * screen that starts something asynchronous wants to know when it finishes,
 * the quickest way to find out is a `setInterval`, and by the fifth screen
 * there are five different intervals, five different stop conditions and one
 * of them never stops. That is a polling storm, and it is measured in the
 * platform's rate limits rather than in anybody's inbox.
 *
 * So the rule is structural. `watchOperation.ts` holds the schedule, and
 * `queries.ts` is the only other file allowed to reference `refetchInterval` —
 * because a list's interval is still the same rule, asked from the query
 * layer. Anything else that wants to know when to look again asks one of them.
 *
 * A gate over source text rather than over behaviour, deliberately: the
 * behaviour of a timer nobody knows about is precisely what cannot be
 * asserted.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

/** Where the rule lives, and the one caller allowed to ask it for a list. */
const SCHEDULERS = ['lib/watchOperation.ts', 'lib/queries.ts']

/**
 * The operator surface, which refreshes rather than watches.
 *
 * An admin screen showing the provisioning queue or the drift report is a
 * monitoring view of live state: it wants a steady refresh for as long as
 * somebody is looking at it, and there is no operation whose finishing would
 * make it stop. That is a different thing from watching one customer's reboot
 * until it settles, and giving it the operation rule would mean an operator's
 * dashboard that stopped updating.
 *
 * Listed as one file with the reason written down, rather than excluded by a
 * pattern that would also quietly excuse the next customer screen somebody
 * puts a timer in.
 */
const OPERATOR_REFRESH = ['lib/adminQueries.ts']

/**
 * Timers that are not polling.
 *
 * A countdown on a resend button and a toast that clears itself are not
 * asking the server anything, so they are not what this gate is about. Each
 * exception is a file, not a pattern, so adding one is a decision somebody
 * makes here rather than a name they happen to choose.
 */
const NOT_POLLING = [
  // A visible "you can try again in N seconds" counter beside a resend button.
  'features/auth/VerifyEmailPage.tsx',
  // Messages that clear themselves after a few seconds.
  'components/Toasts.tsx',
]

function sourceFiles(directory: string): string[] {
  const found: string[] = []

  for (const entry of readdirSync(directory)) {
    const entryPath = path.join(directory, entry)

    if (statSync(entryPath).isDirectory()) {
      if (entry === '__tests__') continue

      found.push(...sourceFiles(entryPath))

      continue
    }

    if (entry.endsWith('.ts') || entry.endsWith('.tsx')) found.push(entryPath)
  }

  return found
}

function relative(file: string): string {
  return path.relative(SOURCE, file)
}

describe('one polling mechanism', () => {
  const files = sourceFiles(SOURCE).map((file) => ({
    path: relative(file),
    source: readFileSync(file, 'utf8'),
  }))

  it('has source files to check', () => {
    // A gate that silently walked an empty directory would pass forever.
    expect(files.length).toBeGreaterThan(50)
  })

  it('schedules a repeat read from one module and nowhere else', () => {
    const offenders = files
      .filter(({ path }) => !SCHEDULERS.includes(path) && !OPERATOR_REFRESH.includes(path))
      .filter(({ source }) => source.includes('refetchInterval'))
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  it('has no timer loops outside the two places that are not polling', () => {
    const offenders = files
      .filter(
        ({ path }) =>
          !SCHEDULERS.includes(path) &&
          !NOT_POLLING.includes(path) &&
          !OPERATOR_REFRESH.includes(path),
      )
      .filter(({ source }) => /\b(setInterval|setTimeout)\s*\(/.test(source))
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  it('keeps the polling rule where the tests can reach it', () => {
    const rule = files.find(({ path }) => path === 'lib/watchOperation.ts')

    expect(rule).toBeDefined()

    /*
     * The two properties the rule must keep, asserted as text because they are
     * what the behavioural tests in when-to-look-again.test.ts exercise and
     * what a refactor could quietly drop: the schedule comes from the server's
     * hint, and there is a ceiling.
     */
    expect(rule?.source).toContain('poll_after_ms')
    expect(rule?.source).toContain('MAX_INTERVAL_MS')
  })
})
