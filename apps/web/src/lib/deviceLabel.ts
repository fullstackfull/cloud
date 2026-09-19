/**
 * A human name for the device behind a session, from its user-agent string.
 *
 * The security page used to print the raw header, truncated at 22rem with the
 * rest in a `title` attribute. A customer looking for the session they do not
 * recognise was reading:
 *
 * ```text
 * Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML,…
 * ```
 *
 * — which is the same first sixty characters on almost every desktop session
 * they have, so the one control that matters on that page ("sign this one out")
 * could not be aimed.
 *
 * ## What this deliberately is not
 *
 * It is not a fingerprinting library. There is no version detection, no device
 * model, no "iPhone 14 Pro", and nothing is inferred from anything but the
 * substrings below. A user-agent string is a claim made by the client, it is
 * routinely frozen, spoofed and reduced by the browsers themselves, and a
 * platform that printed "iPhone 14 Pro in Kuwait City" from one would be
 * making up two facts to dress up a third.
 *
 * So: a browser family and an operating-system family, each from a short
 * ordered list, and `null` when the string does not say. `null` renders as
 * "Unknown browser" rather than as a guess — an unrecognised session is
 * exactly the one a customer needs to be told the truth about.
 *
 * ## Order matters
 *
 * Every one of these strings contains the ones below it. Edge says it is
 * Chrome, Chrome says it is Safari, and Safari says it is Mozilla; a list
 * checked in the wrong order reports every Edge session as Chrome, which is
 * worse than reporting none, because it is confidently wrong.
 */

/** Browser families, most specific claim first. */
const BROWSERS: Array<{ id: string; pattern: RegExp }> = [
  { id: 'edge', pattern: /\bEdgA?i?O?S?\/|\bEdge\//i },
  { id: 'opera', pattern: /\bOPR\/|\bOpera[\s/]/i },
  { id: 'samsung', pattern: /\bSamsungBrowser\//i },
  { id: 'firefox', pattern: /\bFirefox\/|\bFxiOS\//i },
  { id: 'chrome', pattern: /\bChrome\/|\bCriOS\//i },
  { id: 'safari', pattern: /\bSafari\//i },
]

/** Operating systems, most specific first: iPadOS says it is Mac OS X. */
const PLATFORMS: Array<{ id: string; pattern: RegExp }> = [
  { id: 'iphone', pattern: /\biPhone\b|\biPod\b/i },
  { id: 'ipad', pattern: /\biPad\b/i },
  { id: 'android', pattern: /\bAndroid\b/i },
  { id: 'windows', pattern: /\bWindows NT\b/i },
  { id: 'chromeos', pattern: /\bCrOS\b/i },
  { id: 'macos', pattern: /\bMac OS X\b|\bMacintosh\b/i },
  { id: 'linux', pattern: /\bLinux\b|\bX11\b/i },
]

export interface Device {
  /** A browser family id, or null when the string does not say. */
  browser: string | null
  /** An operating-system family id, or null. */
  platform: string | null
}

/**
 * The two families a user-agent string claims, or nulls.
 *
 * Bounded by construction: the return values can only ever be ids from the two
 * lists above, so the portal's translation catalogue can cover every one of
 * them and a gate can prove that it does.
 */
export function readDevice(userAgent: string | null | undefined): Device {
  if (userAgent === null || userAgent === undefined || userAgent.trim() === '') {
    return { browser: null, platform: null }
  }

  const browser = BROWSERS.find((candidate) => candidate.pattern.test(userAgent))?.id ?? null
  const platform = PLATFORMS.find((candidate) => candidate.pattern.test(userAgent))?.id ?? null

  return { browser, platform }
}

/** Every browser id this parser can produce. Read by the translation gate. */
export function browserIds(): string[] {
  return BROWSERS.map((browser) => browser.id)
}

/** Every platform id this parser can produce. Read by the translation gate. */
export function platformIds(): string[] {
  return PLATFORMS.map((platform) => platform.id)
}
