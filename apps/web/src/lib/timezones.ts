/**
 * The time zones a customer may choose from.
 *
 * The profile used to ask them to type one. The server validated it — Laravel's
 * `timezone` rule refuses anything that is not an identifier PHP knows — so
 * nothing invalid could be stored, and the experience was still wrong: a
 * customer in Kuwait had to know that the string is `Asia/Kuwait` and not
 * "Kuwait", "GMT+3", "AST" or "Arabia Standard Time", and three of those four
 * are refused with a validation error that cannot explain itself.
 *
 * ## Where the list comes from
 *
 * `Intl.supportedValuesOf('timeZone')` — the browser's own IANA database, the
 * same one that will render every date the customer then sees. That is the
 * property worth having: a zone chosen from this list is by construction a
 * zone the formatter can use, which is what §13 is asking for and what a
 * hand-maintained list of "major cities" would quietly stop being the first
 * time tzdata moved.
 *
 * It also costs nothing. The obvious alternative is a zone-picker package,
 * which is several hundred kilobytes of data the browser already has.
 *
 * ## The fallback
 *
 * `supportedValuesOf` is recent enough that a customer on an older browser
 * would otherwise get an empty dropdown, which is worse than the text box it
 * replaced. The fallback is short and deliberately regional: the zones
 * Lynomia's customers are actually in, plus UTC. It is a floor, not a claim to
 * completeness — and the customer's own stored zone is always added to
 * whichever list is used, so nobody is ever shown a picker that cannot
 * represent what they already have.
 */

const FALLBACK = [
  'UTC',
  'Asia/Kuwait',
  'Asia/Riyadh',
  'Asia/Dubai',
  'Asia/Qatar',
  'Asia/Bahrain',
  'Asia/Muscat',
  'Asia/Baghdad',
  'Asia/Amman',
  'Asia/Beirut',
  'Africa/Cairo',
  'Europe/Istanbul',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'America/New_York',
  'America/Chicago',
  'America/Los_Angeles',
  'Asia/Kolkata',
  'Asia/Singapore',
  'Asia/Tokyo',
  'Australia/Sydney',
]

/**
 * Every zone the browser can format, with `current` guaranteed to be present.
 *
 * `current` is included even when the browser does not know it: a value stored
 * against an older tzdata must still be selectable, or opening the profile and
 * pressing save would silently change the customer's time zone to whatever the
 * dropdown happened to be showing.
 */
export function timeZones(current: string | null | undefined): string[] {
  const supported = readSupported()

  /*
   * `UTC` is deliberately added rather than assumed. The canonical list the
   * browser returns contains 418 named places and not one of them is UTC —
   * not even `Etc/UTC` — because UTC is not a place. It is nonetheless a
   * formattable zone, a stored value the server accepts, and the deliberate
   * choice of exactly the customer running servers in four countries who does
   * not want their invoice dates shifting with a city. Leaving it out would
   * have meant the only way to choose it was to have had it already.
   */
  const zones = supported.length > 0 ? ['UTC', ...supported] : FALLBACK

  if (current === null || current === undefined || current === '' || zones.includes(current)) {
    return zones
  }

  return [current, ...zones]
}

function readSupported(): string[] {
  // Guarded rather than assumed: this is a recent addition to Intl, and an
  // empty dropdown is worse than the free-text box this replaces.
  const intl = Intl as typeof Intl & { supportedValuesOf?: (key: string) => string[] }

  if (typeof intl.supportedValuesOf !== 'function') return []

  try {
    return intl.supportedValuesOf('timeZone')
  } catch {
    return []
  }
}

/**
 * Whether the browser can actually format a date in this zone.
 *
 * Used to tell the customer that their stored zone is one this browser does
 * not know, rather than letting every date on the page quietly fall back to
 * the browser's own zone with no explanation.
 */
export function canFormatIn(zone: string): boolean {
  try {
    new Intl.DateTimeFormat('en', { timeZone: zone }).format(new Date(0))

    return true
  } catch {
    return false
  }
}
