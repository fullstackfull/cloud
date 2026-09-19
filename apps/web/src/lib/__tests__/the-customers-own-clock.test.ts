import { afterEach, describe, expect, it } from 'vitest'

import { activeZone, applyTimeZone, formatDate, formatDateTime, formatRelative } from '@/lib/format'

/**
 * AS-17. Instants belong to the customer's time zone; calendar dates belong to
 * no time zone at all.
 *
 * Both halves of that sentence were wrong before this wave. Every timestamp
 * was rendered in whatever zone the browser was set to, so a customer in
 * Kuwait reading from a laptop still on Europe/London saw their own reboot
 * happen two hours before they pressed the button. And a date with no time in
 * it — a renewal date, a domain's expiry — is midnight UTC when parsed, so
 * applying a zone behind UTC to it moves it to the previous day.
 */
describe("the customer's own clock", () => {
  afterEach(() => {
    applyTimeZone(null)
  })

  it('reads an instant in the zone the customer chose', () => {
    // 23:30 UTC is half past two the next morning in Kuwait (UTC+3).
    const midnightish = '2027-03-09T23:30:00Z'

    applyTimeZone('Asia/Kuwait')
    const kuwait = formatDateTime(midnightish, 'en')

    applyTimeZone('America/Los_Angeles')
    const california = formatDateTime(midnightish, 'en')

    expect(kuwait).toContain('10')
    expect(kuwait).toContain('02:30')

    // The same instant, a different day and a different clock. Both true; only
    // one of them is the customer's.
    expect(california).toContain('09')
    expect(california).toContain('03:30')
    expect(california).toContain('PM')
  })

  it('never moves a date that has no time in it', () => {
    /*
     * The failure this prevents: a renewal on the 9th, rendered for a customer
     * in a zone behind UTC, reading as the 8th — on the screen that says when
     * money is taken.
     */
    for (const zone of ['Asia/Kuwait', 'America/Los_Angeles', 'Pacific/Kiritimati']) {
      applyTimeZone(zone)

      expect(formatDate('2027-03-09', 'en')).toContain('09')
      expect(formatDate('2027-03-09', 'ar')).toContain('09')
    }
  })

  it('ignores a zone it cannot resolve rather than blanking the page', () => {
    applyTimeZone('Mars/Olympus_Mons')

    expect(activeZone()).toBeUndefined()

    // And still renders: the browser's own zone is a worse answer than the
    // customer's and a far better one than an empty cell.
    expect(formatDateTime('2027-03-09T14:05:00Z', 'en')).toContain('2027')
  })

  it('treats a cleared zone as the browser default', () => {
    applyTimeZone('Asia/Kuwait')
    expect(activeZone()).toBe('Asia/Kuwait')

    applyTimeZone(null)
    expect(activeZone()).toBeUndefined()
  })

  it('says how long ago in the reader s own language, from Intl rather than a string table', () => {
    const twoHoursAgo = new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString()

    const english = formatRelative(twoHoursAgo, 'en')
    const arabic = formatRelative(twoHoursAgo, 'ar')

    expect(english).toMatch(/hour/)
    expect(arabic).toMatch(/\p{Script=Arabic}/u)

    // Relative time is a difference between instants, so it is the one date
    // format a wrongly-set browser clock zone cannot get wrong.
    applyTimeZone('Pacific/Kiritimati')
    expect(formatRelative(twoHoursAgo, 'en')).toBe(english)
  })
})
