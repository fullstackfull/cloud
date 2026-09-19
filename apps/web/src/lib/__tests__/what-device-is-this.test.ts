import { describe, expect, it } from 'vitest'

import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'
import { browserIds, platformIds, readDevice } from '@/lib/deviceLabel'

/**
 * The security page asks one question — "is one of these not me?" — and until
 * this parser existed it answered with sixty characters of `Mozilla/5.0
 * (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36…`, which is the same
 * sixty characters on every desktop session a customer has.
 *
 * What is asserted is the two properties that make the replacement safe rather
 * than merely prettier: it recognises the real strings, and it says "unknown"
 * instead of guessing.
 */
describe('reading a user agent', () => {
  const REAL: Array<[string, string, string | null, string | null]> = [
    [
      'Chrome on macOS',
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
      'chrome',
      'macos',
    ],
    [
      'Safari on iPhone',
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
      'safari',
      'iphone',
    ],
    [
      'Edge on Windows',
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
      'edge',
      'windows',
    ],
    [
      'Firefox on Linux',
      'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
      'firefox',
      'linux',
    ],
    [
      'Samsung Internet on Android',
      'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
      'samsung',
      'android',
    ],
    [
      'Safari on iPad, which claims to be a Mac',
      'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/604.1',
      'safari',
      'ipad',
    ],
  ]

  it.each(REAL)('reads %s', (_name, userAgent, browser, platform) => {
    expect(readDevice(userAgent)).toEqual({ browser, platform })
  })

  it('never reports the browser a string merely claims to be compatible with', () => {
    /*
     * Every one of these strings contains the names of the browsers below it:
     * Edge says Chrome, Chrome says Safari, Safari says Mozilla. A list
     * checked in the wrong order reports every Edge session as Chrome, which
     * is worse than reporting none — it is confidently wrong, on the screen a
     * customer uses to spot the session that is not theirs.
     */
    const edge =
      'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0'

    expect(edge).toContain('Chrome/')
    expect(edge).toContain('Safari/')
    expect(readDevice(edge).browser).toBe('edge')
  })

  it('says it does not know rather than guessing', () => {
    expect(readDevice('curl/8.4.0')).toEqual({ browser: null, platform: null })
    expect(readDevice('')).toEqual({ browser: null, platform: null })
    expect(readDevice(null)).toEqual({ browser: null, platform: null })
    expect(readDevice(undefined)).toEqual({ browser: null, platform: null })
  })

  it('reads a platform even when the browser is one it does not know', () => {
    // Half an answer is still an answer, and "something on Windows" is more
    // than the customer had.
    expect(readDevice('SomeNewBrowser/2.0 (Windows NT 10.0)')).toEqual({
      browser: null,
      platform: 'windows',
    })
  })

  it('infers nothing the string does not say', () => {
    /*
     * No device model, no version, no location. A user agent is a claim made
     * by the client — routinely frozen, spoofed and reduced by the browsers
     * themselves — and a platform that printed "iPhone 14 Pro in Kuwait City"
     * from one would be inventing two facts to dress up a third.
     */
    const device = readDevice(
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Version/17.4 Mobile/15E148 Safari/604.1',
    )

    expect(Object.keys(device).sort()).toEqual(['browser', 'platform'])
  })
})

describe('every device word a screen can show', () => {
  /**
   * The parser is bounded on purpose — it can only return ids from its own two
   * lists — and that boundedness is worth nothing if the catalogue does not
   * cover them. A missing string here renders `security.browsers.samsung` on
   * the security page, which is the class of defect the backend enum gate
   * exists for and which this vocabulary lives outside of.
   */
  it.each([
    ['English', en],
    ['Arabic', ar],
  ])('is translated in %s', (_language, catalogue) => {
    const security = (catalogue as unknown as { security: Record<string, unknown> }).security
    const browsers = security['browsers'] as Record<string, string> | undefined
    const platforms = security['platforms'] as Record<string, string> | undefined

    for (const id of browserIds()) {
      expect(browsers?.[id], `security.browsers.${id}`).toBeTruthy()
    }

    for (const id of platformIds()) {
      expect(platforms?.[id], `security.platforms.${id}`).toBeTruthy()
    }

    // And the two sentences used when the string says nothing.
    expect(security['unknownBrowser']).toBeTruthy()
    expect(security['unknownPlatform']).toBeTruthy()
    expect(security['unknownDevice']).toBeTruthy()
  })
})
