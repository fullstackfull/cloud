import { describe, expect, it } from 'vitest'

import { formatDate, formatDateTime } from '@/lib/format'

/**
 * The Arabic domains page once showed "092027/03/": a numeric date whose
 * right-to-left marks reordered inside a left-to-right cell. A date is now a
 * named month inside bidi isolates, in both languages.
 */
describe('dates', () => {
  const iso = '2027-03-09T14:05:00Z'

  it('renders coherently in English', () => {
    const value = formatDate(iso, 'en')
    expect(value).toMatch(/^⁨.*⁩$/)
    expect(value).toContain('2027')
    expect(value).toMatch(/Mar/)
    expect(value).not.toMatch(/\//)
  })

  it('renders coherently in Arabic, with a named month and no slashes to reorder', () => {
    const value = formatDate(iso, 'ar')
    expect(value).toMatch(/^⁨.*⁩$/)
    expect(value).toContain('2027')
    expect(value).toMatch(/\p{Script=Arabic}/u)
    expect(value).not.toMatch(/\//)
    // Western numerals in both languages.
    expect(value).not.toMatch(/[٠-٩]/)
    // Nothing that could read as the audit's malformed string.
    expect(value.replace(/[⁨⁩‏]/g, '')).not.toMatch(/^\d{2}\d{4}\//)
  })

  it('keeps the time beside the date in both languages', () => {
    for (const locale of ['en', 'ar'] as const) {
      const value = formatDateTime(iso, locale)
      expect(value).toContain('2027')
      expect(value).toMatch(/\d{2}:\d{2}/)
    }
  })
})
