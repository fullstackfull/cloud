import { describe, expect, it } from 'vitest'

import ar from '../../../i18n/locales/ar.json'
import en from '../../../i18n/locales/en.json'

/**
 * Two plan-change refusals used to promise what the platform cannot keep (a
 * residue the round-six verifiers recorded).
 *
 * `not_deliverable` said the plan was "temporarily" unavailable and to "try
 * again later". The refusal is also the answer for a change of shape nothing
 * can make - a dedicated server is never resized - and that does not pass.
 * `previous_change_pending` said the last change was "still being applied",
 * which is true only while its settlement is on its way: one whose listener
 * exhausted its retries is never applied, and the refusal lasts the period.
 */
describe('the plan-change refusals', () => {
  const refusals = {
    en: en.planChange.refusal,
    ar: ar.planChange.refusal,
  }

  it('does not promise that an undeliverable plan will become available', () => {
    expect(refusals.en.not_deliverable).not.toMatch(/temporar|try again|later/i)
    expect(refusals.ar.not_deliverable).not.toMatch(/مؤقت|لاحق/)
    expect(refusals.en.not_deliverable).toMatch(/Nothing has been charged/)
  })

  it('does not say a paid change is being applied, and says where to go if it is not', () => {
    expect(refusals.en.previous_change_pending).not.toMatch(/still being applied|when it has finished/i)
    expect(refusals.en.previous_change_pending).toMatch(/contact support/i)
    expect(refusals.ar.previous_change_pending).not.toMatch(/قيد التطبيق/)
    expect(refusals.ar.previous_change_pending).toMatch(/الدعم/)
  })
})
