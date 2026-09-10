import { describe, expect, it } from 'vitest'

import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'
import { STATUS_TONES, toneFor } from '@/lib/statusVocabulary'

/**
 * Every customer-visible status has a sentence in both languages and a tone,
 * or the build is red. This is the test the audit asked for: the badge used to
 * tone 66 of 117 statuses and leave the rest grey, and nothing stopped a new
 * status from arriving untranslated.
 */
describe('status vocabulary', () => {
  const englishKeys = Object.keys(en.status).sort()
  const arabicKeys = Object.keys(ar.status).sort()
  const tonedKeys = Object.keys(STATUS_TONES).sort()

  it('translates every status in both languages', () => {
    expect(arabicKeys).toEqual(englishKeys)
    for (const key of englishKeys) {
      expect((ar.status as Record<string, string>)[key]).toMatch(/\p{Script=Arabic}/u)
    }
  })

  it('gives every translated status an explicit tone', () => {
    expect(englishKeys.filter((key) => !(key in STATUS_TONES))).toEqual([])
  })

  it('has no tone for a status nobody translated', () => {
    expect(tonedKeys.filter((key) => !englishKeys.includes(key))).toEqual([])
  })

  it('does not lie with colour', () => {
    // States that need a person, failed, or are over must never look healthy
    // or neutral; states in progress must never look complete.
    for (const key of ['needs_review', 'manual_review', 'under_review']) expect(toneFor(key)).toBe('warning')
    for (const key of ['payment_failed', 'provisioning_failed', 'failed', 'expired', 'indeterminate', 'refused']) {
      expect(toneFor(key)).toBe('danger')
    }
    for (const key of ['processing', 'provisioning', 'queued_for_provisioning', 'pending']) expect(toneFor(key)).toBe('info')
    expect(toneFor('active')).toBe('success')
    expect(toneFor('active')).not.toBe(toneFor('expired'))
  })

  it('falls back to neutral for a value the API has not taught it, rather than guessing', () => {
    expect(toneFor('something_new')).toBe('neutral')
  })
})
