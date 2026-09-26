import { readFileSync } from 'node:fs'
import path from 'node:path'

import { getConfig } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

/**
 * F-42: the required vitest gate runs on budgets somebody chose.
 *
 * The finding was a gate red on load alone, because every clock in it was a
 * framework default nobody had picked: vitest's 5,000 ms per test and hook,
 * Testing Library's 1,000 ms per `findBy*`, and as many forks as cores less
 * one. The values now live in vitest.config.ts and src/test-setup.ts, each
 * with the measurement that sized it. This file is what keeps them there: it
 * reads the budgets the running test was actually given, not the source that
 * sets them, so a value overridden anywhere between the config and the test
 * shows up here as what it is.
 *
 * What it does not know: whether the values are enough on a machine loaded
 * more heavily than the one they were measured on. That is a measurement,
 * and the docblocks record the one that was made.
 */
describe('the suite runs on budgets somebody chose', () => {
  it('gives each test thirty seconds, not the five it inherited', ({ task }) => {
    expect(task.timeout).toBe(30_000)
  })

  it('never retries a failing test into a pass', ({ task }) => {
    // A retry would make the load-triggered failures F-42 is about disappear
    // from the report without anything having been fixed.
    expect(task.retry ?? 0).toBe(0)
  })

  it('gives a findBy* ten seconds, not the one it inherited', () => {
    expect(getConfig().asyncUtilTimeout).toBe(10_000)
  })

  it('keeps that inside the test budget, so a missing element fails on its own message', ({ task }) => {
    expect(getConfig().asyncUtilTimeout * 2).toBeLessThan(task.timeout)
  })

  /*
   * The pool, the fork cap and the hook budget are not visible from inside a test, so
   * this row reads vitest.config.ts's source text: it asks for one line per
   * key named, anywhere in the file, and cannot tell whether that line is
   * inside the `test` block or overridden later (`--maxWorkers` on the
   * command line, for one, would win over it).
   */
  it('caps the forks at half the cores and gives hooks the same budget as tests', () => {
    const config = readFileSync(path.resolve(import.meta.dirname, '../../../vitest.config.ts'), 'utf8')

    expect(config).toMatch(/^\s*pool: 'forks',$/m)
    expect(config).toMatch(/^\s*maxWorkers: '50%',$/m)
    expect(config).toMatch(/^\s*hookTimeout: 30_000,$/m)
  })
})
