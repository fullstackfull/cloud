import { describe, expect, it } from 'vitest'

import type { CustomerOperation, CustomerOperationState, RetryAdvice } from '@/lib/types'
import { OBSERVATION_WINDOW_MS, nextPollDelay } from '@/lib/watchOperation'

/**
 * The polling rule, on its own.
 *
 * §51-56 are a set of claims about when the portal asks the server about work
 * in flight, and every one of them is decided by this one function. Testing it
 * directly rather than through a rendered screen is the difference between a
 * test that asserts the rule and a test that asserts a component happened to
 * behave for one fixture.
 */

function operation(
  state: CustomerOperationState,
  pollAfterMs: number | null,
  requestedAt: string | null = new Date().toISOString(),
): CustomerOperation {
  const terminal = state !== 'queued' && state !== 'processing'

  const advice: RetryAdvice = terminal ? 'support_required' : 'wait'

  return {
    id: '01JOPERATION',
    kind: 'restart',
    action: 'reboot',
    state,
    is_terminal: terminal,
    needs_attention: false,
    retry_advice: advice,
    failure_reason: null,
    resource: null,
    requested_at: requestedAt,
    started_at: null,
    updated_at: null,
    finished_at: terminal ? new Date().toISOString() : null,
    poll_after_ms: pollAfterMs,
  }
}

describe('when to look at an operation again', () => {
  it('waits for the first read rather than scheduling one before it', () => {
    // The query's own initial fetch does this. Scheduling an interval as well
    // would be two requests for the first look.
    expect(nextPollDelay(undefined, 0, null)).toBe(false)
  })

  it('honours the interval the server asked for', () => {
    expect(nextPollDelay(operation('processing', 3_000), 0, 1_000)).toBe(3_000)
  })

  it.each([
    ['succeeded' as const],
    ['failed' as const],
    ['needs_review' as const],
    ['indeterminate' as const],
    ['cancelled' as const],
  ])('stops the moment the work is over: %s', (state) => {
    /*
     * The server sends a null hint for every terminal state, and this is the
     * client half of the same rule. A screen that kept asking about a rebuild
     * that finished an hour ago would be a screen nobody noticed was doing it.
     */
    expect(nextPollDelay(operation(state, null), 4, 60_000)).toBe(false)
  })

  it('widens the gap as the reads add up', () => {
    const running = operation('processing', 3_000)

    const first = nextPollDelay(running, 0, 1_000)
    const tenth = nextPollDelay(running, 10, 60_000)

    expect(first).toBe(3_000)
    expect(Number(tenth)).toBeGreaterThan(Number(first))
  })

  it('never backs off past the ceiling', () => {
    // A tab left open on a long build must not drift into asking once an hour,
    // which would make the screen stale in the other direction.
    expect(nextPollDelay(operation('processing', 10_000), 500, 60_000)).toBe(30_000)
  })

  it('gives up asking once the work has outrun the observation window', () => {
    const stuck = operation('processing', 3_000)

    expect(nextPollDelay(stuck, 20, OBSERVATION_WINDOW_MS + 1)).toBe(false)

    // And is still watching right up to the edge: the boundary is not an
    // off-by-one away from a screen that stops watching a healthy build.
    expect(nextPollDelay(stuck, 20, OBSERVATION_WINDOW_MS - 1)).not.toBe(false)
  })

  it('keeps watching when the server sent no timestamp to measure from', () => {
    /*
     * The window is a property of the operation, not of the browser tab. With
     * nothing to measure from, the honest behaviour is to keep asking while
     * the server keeps sending a hint — not to invent a start time and stop
     * on it.
     */
    expect(nextPollDelay(operation('processing', 3_000, null), 3, null)).not.toBe(false)
  })
})
