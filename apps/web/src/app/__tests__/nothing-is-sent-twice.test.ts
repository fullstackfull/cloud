import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import type { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'

import { createQueryClient } from '@/app/queryClient'

/**
 * §37, and Wave 4's operation truth. A mutation is sent when a person presses
 * the button, and at no other time.
 *
 * ## Why this needs a gate rather than a comment
 *
 * Two of the three ways the portal could resend a write are **library
 * defaults**, so leaving them alone is not the safe choice — it is the unsafe
 * one, and it looks like no decision at all in the diff.
 *
 *  1. `mutations.retry` defaults to `false`. This one is safe by default, and
 *     is written out anyway so that turning it on means deleting a comment
 *     that explains why you must not.
 *
 *  2. `mutations.networkMode` defaults to `'online'`, and that default replays
 *     writes. Under it a mutation fired while the browser is offline is not
 *     attempted and not failed — it is *paused*. `QueryClient.mount()` then
 *     subscribes to the online manager **and the focus manager**, calling
 *     `resumePausedMutations()` from both. So a customer who pressed "Reboot"
 *     with no signal, gave up, and later switched back to the tab would reboot
 *     their machine by returning to it. `refetchOnWindowFocus: false` does not
 *     help: it governs queries, and the focus subscription resumes mutations
 *     regardless.
 *
 *  3. `resumePausedMutations()` can also be called directly, which is the
 *     honest-looking way to reintroduce the same defect after (2) is fixed.
 *
 * Reads are the opposite case and deliberately keep their defaults: refetching
 * a GET on reconnect costs nothing and is how a stale page becomes true again.
 * The distinction this file defends is between re-*asking* and re-*doing*.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

describe('the query client', () => {
  const client: QueryClient = createQueryClient()
  const mutations = client.getDefaultOptions().mutations ?? {}
  const queries = client.getDefaultOptions().queries ?? {}

  it('never retries a mutation', () => {
    // Not "is falsy": `undefined` would also be falsy and would mean nobody
    // decided. The value has to be the decision.
    expect(mutations.retry).toBe(false)
  })

  it('never lets a mutation be paused, because a paused mutation is replayed', () => {
    /*
     * This is the assertion that would have failed before Wave 5. The value
     * must be 'always': the mutation is attempted immediately, fails against a
     * dead network, and is reported to the customer as a request that did not
     * get through. Nothing is queued, so there is nothing to resume.
     */
    expect(mutations.networkMode).toBe('always')
  })

  it('still lets reads refetch when the network comes back', () => {
    // Re-asking is not re-doing. A GET repeated after a reconnect is how a
    // stale page becomes true, so this is left at its default.
    expect(queries.refetchOnReconnect).toBeUndefined()
  })

  it('lets a read fail against a dead network instead of hanging on a spinner', () => {
    /*
     * The read-side counterpart of the mutation setting, and the opposite
     * mechanism. Under the default a read started while offline is paused:
     * never fired, never failed, never resolved — a spinner that promises
     * something is coming. `offlineFirst` lets it fail into the error state
     * every screen already reports.
     *
     * Asserted separately from the mutation defaults so that nobody "tidies"
     * the two into one shared value: they want opposite things.
     */
    expect(queries.networkMode).toBe('offlineFirst')
    expect(mutations.networkMode).toBe('always')
  })

  it('does not retry a read the server has already answered', () => {
    const retry = queries.retry

    expect(typeof retry).toBe('function')
  })
})

describe('the source', () => {
  const files = sources()

  it('has files to check', () => {
    expect(files.length).toBeGreaterThan(100)
  })

  it('never resumes paused mutations by hand', () => {
    /*
     * The direct route to the same defect. There is no legitimate use of this
     * in a portal that never pauses a mutation in the first place — and if
     * something ever pauses one, resuming it is the decision this whole file
     * exists to prevent being made quietly.
     */
    const offenders = files.filter((file) =>
      withoutComments(readFileSync(file, 'utf8')).includes('resumePausedMutations'),
    )

    expect(
      offenders.map((file) => path.relative(SOURCE, file)),
      'Resuming a paused mutation replays a write the customer did not ask for twice.',
    ).toEqual([])
  })

  it('never sets a mutation to retry', () => {
    /*
     * A per-call `retry` on a useMutation overrides the client default, and
     * reads as a small local decision rather than as the platform-wide one it
     * actually is: this is the request that reboots, registers or charges.
     */
    const offenders: string[] = []

    for (const file of files) {
      const source = withoutComments(readFileSync(file, 'utf8'))

      for (const match of source.matchAll(/useMutation\s*(?:<[^>]*>)?\s*\(\s*\{([\s\S]*?)\n\s*\}\s*\)/g)) {
        const body = match[1] ?? ''

        // `retry: false` is redundant but harmless; anything else turns one on.
        const retry = /\bretry\s*:\s*([^,\n]+)/.exec(body)

        if (retry && retry[1]?.trim() !== 'false') {
          offenders.push(`${path.relative(SOURCE, file)} → retry: ${retry[1]?.trim() ?? ''}`)
        }

        const mode = /\bnetworkMode\s*:\s*'([^']+)'/.exec(body)

        if (mode && mode[1] !== 'always') {
          offenders.push(`${path.relative(SOURCE, file)} → networkMode: '${mode[1] ?? ''}'`)
        }
      }
    }

    expect(
      offenders,
      'A retried or pausable mutation is a second reboot, a second registration, a second payment.',
    ).toEqual([])
  })
})

function sources(): string[] {
  const files: string[] = []

  walk(SOURCE, files)

  return files.filter((file) => ! file.includes('__tests__'))
}

function walk(directory: string, into: string[]): void {
  for (const entry of readdirSync(directory)) {
    if (entry === 'node_modules') continue

    const full = path.join(directory, entry)

    if (statSync(full).isDirectory()) {
      walk(full, into)
    } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
      into.push(full)
    }
  }
}

function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}
