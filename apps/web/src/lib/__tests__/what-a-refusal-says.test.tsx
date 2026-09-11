import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import '@/i18n'
import { ApiError, NetworkError } from '@/lib/api'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * W5.7, the customer error catalogue seen from the portal's side.
 *
 * The control plane owns the sentences: `lang/{en,ar}/errors.php` carries one
 * per code a customer can be answered with, `ErrorCatalogue` is the boundary
 * that keeps the exception's own English prose out of the response, and
 * `CustomerErrorCatalogueTest` derives the set of codes from the customer
 * modules' routes and fails the build when one has no English or Arabic
 * sentence. There were 286 codes at W5.7, in both languages, with no
 * difference between the two catalogues.
 *
 * What this file checks is the other end of that pipe: that whatever arrives,
 * the portal renders a *sentence*. Three things must never reach a screen —
 * a bare code, a translation key, and the server's own words on a failure it
 * did not attribute to the customer — and each has its own branch here.
 */

function describeError(): (error: unknown) => { message: string; requestId: string | undefined } | null {
  const { result } = renderHook(() => useApiErrorMessage())

  return result.current
}

function refusal(status: number, code: string, message: string, requestId?: string): ApiError {
  return new ApiError(status, {
    code,
    message,
    ...(requestId === undefined ? {} : { request_id: requestId }),
  })
}

describe('what a refusal says to a customer', () => {
  it('prefers the portal’s own sentence when it has one', () => {
    const shown = describeError()(refusal(422, 'validation.failed', 'The given data was invalid.'))

    // The portal's wording for a code it has an opinion about, not the API's.
    expect(shown?.message).toBe('Please correct the highlighted fields.')
  })

  it('shows the API’s sentence for a customer refusal it has no wording of its own for', () => {
    /*
     * The API composed it from the customer catalogue in the language this
     * request asked for, so it is already translated. Duplicating two hundred
     * sentences into a second catalogue to avoid using it would be two places
     * to keep in step, and the second one would lose.
     */
    const shown = describeError()(
      refusal(409, 'domains.transfer_locked', 'This domain is locked against transfer.'),
    )

    expect(shown?.message).toBe('This domain is locked against transfer.')
  })

  it('never shows the code itself', () => {
    // An empty message with an unknown code is the case that used to render
    // the code: "domains.some_new_refusal" in the middle of a sentence.
    const shown = describeError()(refusal(409, 'domains.some_new_refusal', ''))

    expect(shown?.message).not.toContain('domains.some_new_refusal')
    expect(shown?.message).toBe('Something went wrong on our side.')
  })

  it('never shows a translation key', () => {
    const shown = describeError()(refusal(409, 'nothing.written.for.this', ''))

    expect(shown?.message).not.toMatch(/^errors\./)
    expect(shown?.message).not.toContain('nothing.written.for.this')
  })

  it('does not repeat the server’s words on a failure that is not the customer’s', () => {
    /*
     * A 5xx message is written for whoever reads the log and is allowed to
     * name a node, a driver or a provider. It is exactly the text this portal
     * must not put on a customer's screen — so the branch is on the status,
     * not on how the sentence reads.
     */
    const shown = describeError()(
      refusal(500, 'server.error', 'SQLSTATE[08006] could not connect to node pve-02', 'req_01J'),
    )

    expect(shown?.message).not.toContain('pve-02')
    expect(shown?.message).not.toContain('SQLSTATE')
    expect(shown?.message).toBe('Something went wrong on our side.')

    // With the reference, which is the half a customer can quote to support.
    expect(shown?.requestId).toBe('req_01J')
  })

  it('says the platform could not be reached when that is what happened', () => {
    const shown = describeError()(new NetworkError('fetch failed'))

    expect(shown?.message).toBe('We could not reach the server. Check your connection and try again.')
  })

  it('has its own sentence for being asked to slow down', () => {
    const shown = describeError()(refusal(429, 'rate_limited', 'Too Many Attempts.'))

    expect(shown?.message).toBe('Too many attempts. Please wait a moment and try again.')
  })

  it('says nothing when nothing went wrong', () => {
    expect(describeError()(null)).toBeNull()
    expect(describeError()(undefined)).toBeNull()
  })
})
