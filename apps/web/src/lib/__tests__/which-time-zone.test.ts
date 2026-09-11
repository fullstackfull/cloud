import { afterEach, describe, expect, it, vi } from 'vitest'

import { canFormatIn, timeZones } from '@/lib/timezones'

/**
 * The profile used to ask the customer to type a time zone.
 *
 * The server validated it, so nothing invalid could be stored, and the
 * experience was still wrong: a customer in Kuwait had to know that the string
 * is `Asia/Kuwait` and not "Kuwait", "GMT+3", "AST" or "Arabia Standard Time".
 * Three of those four are refused by a validator that cannot explain itself.
 *
 * The replacement is a selection, and these tests hold it to the two things
 * that make a selection safe: every option is one the browser can actually
 * format a date in, and the customer's own stored value is always among them.
 */

describe('the list of time zones', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('comes from the browser, not from a list somebody maintains', () => {
    const zones = timeZones('Asia/Kuwait')

    // The real IANA database is large; a hand-written "major cities" list is
    // not. This is the difference being asserted.
    expect(zones.length).toBeGreaterThan(100)
    expect(zones).toContain('Asia/Kuwait')
    expect(zones).toContain('UTC')
  })

  it('offers only zones a date can actually be formatted in', () => {
    /*
     * The property that makes the picker better than the text box: not "this
     * looks like a zone" but "this browser will render every date on every
     * page with it".
     */
    for (const zone of timeZones('Asia/Kuwait').slice(0, 50)) {
      expect(canFormatIn(zone), zone).toBe(true)
    }
  })

  it('keeps the zone the customer already has, even one this browser cannot name', () => {
    /*
     * A value stored against an older tzdata must stay selectable. Without
     * this, opening the profile and pressing save would silently move the
     * customer's time zone to whatever the dropdown happened to be showing —
     * a change they never asked for, made by the act of looking.
     */
    const zones = timeZones('Antarctica/Somewhere_Renamed')

    expect(zones[0]).toBe('Antarctica/Somewhere_Renamed')
  })

  it('does not repeat the stored zone when the browser already lists it', () => {
    const zones = timeZones('Europe/London')

    expect(zones.filter((zone) => zone === 'Europe/London')).toHaveLength(1)
  })

  it('handles a customer with no zone set at all', () => {
    expect(timeZones(null)).toContain('UTC')
    expect(timeZones('')).toContain('UTC')
    expect(timeZones(undefined)).toContain('UTC')
  })

  it('falls back to a written regional list rather than an empty dropdown', () => {
    /*
     * `Intl.supportedValuesOf` is a recent addition. On a browser without it
     * the customer must still get a usable picker — an empty dropdown is
     * strictly worse than the text box this replaces.
     */
    const intl = Object.create(Intl) as typeof Intl
    Object.defineProperty(intl, 'supportedValuesOf', { value: undefined })
    vi.stubGlobal('Intl', intl)

    const zones = timeZones('Asia/Kuwait')

    expect(zones).toContain('Asia/Kuwait')
    expect(zones).toContain('UTC')
    expect(zones.length).toBeLessThan(100)
  })

  it('survives a browser whose supportedValuesOf throws', () => {
    const intl = Object.create(Intl) as typeof Intl
    Object.defineProperty(intl, 'supportedValuesOf', {
      value: () => {
        throw new RangeError('nope')
      },
    })
    vi.stubGlobal('Intl', intl)

    expect(timeZones('Asia/Riyadh')).toContain('Asia/Riyadh')
  })
})

describe('asking whether a zone can be used', () => {
  it('says yes for a zone this browser knows', () => {
    expect(canFormatIn('Asia/Kuwait')).toBe(true)
    expect(canFormatIn('UTC')).toBe(true)
  })

  it('says no rather than throwing on a zone it does not', () => {
    /*
     * This is what lets the profile tell the customer their stored zone is one
     * this browser cannot use, instead of letting every date on the page fall
     * back to the browser's own zone with no explanation anywhere.
     */
    expect(canFormatIn('Mars/Olympus_Mons')).toBe(false)
    expect(canFormatIn('GMT+3')).toBe(false)
  })
})
