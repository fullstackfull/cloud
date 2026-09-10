import type { Locale } from '@/i18n'

/**
 * A monetary amount exactly as the API serialises it: integer minor units plus
 * an ISO-4217 code. The decimal string is authoritative; `minor_units` is what
 * arithmetic uses. Neither is ever a JavaScript number, because a KWD amount
 * beyond a few million fils would start losing precision as a double.
 */
export interface Money {
  minor_units: number
  currency: string
  amount: string
}

/**
 * Formats money for display.
 *
 * The decimal string from the API is parsed only at the final formatting step
 * and never fed back into arithmetic, so a float is never in the value's
 * lifecycle. Numerals stay Western in both languages: an invoice total must be
 * unambiguous and copy-pasteable.
 */
export function formatMoney(money: Money, locale: Locale): string {
  return new Intl.NumberFormat(`${locale}-u-nu-latn`, {
    style: 'currency',
    currency: money.currency,
    currencyDisplay: 'code',
  }).format(Number(money.amount))
}

/**
 * Formats an amount the API sent as bare minor units.
 *
 * Some payloads carry a Money object and some carry `price_minor` beside a
 * currency code — a domain quote is one of the latter, because the price is a
 * column rather than a computed total. The conversion to a decimal happens
 * here and nowhere else, and the divisor comes from the currency itself rather
 * than a constant: KWD has three decimal places, USD has two, and JPY has
 * none. A hardcoded 100 would show a Kuwaiti customer ten times the price.
 */
export function formatMinorUnits(minorUnits: number, currency: string, locale: Locale): string {
  const format = new Intl.NumberFormat(`${locale}-u-nu-latn`, {
    style: 'currency',
    currency,
    currencyDisplay: 'code',
  })

  /*
     * Every currency Intl knows reports this, but the type says it may be
     * absent. Two is the right fallback for the overwhelming majority of
     * currencies and is only ever reached for one Intl cannot resolve — at
     * which point the amount is approximate rather than absent, which is the
     * better of the two failures on a price beside a buy button.
     */
  const digits = format.resolvedOptions().maximumFractionDigits ?? 2

  return format.format(minorUnits / 10 ** digits)
}

/*
 * Dates and times.
 *
 * Two rules, both learned from the Arabic domains page.
 *
 * The month is a word, never a number. Intl's medium Arabic date is
 * "09‏/03‏/2027" — digits, slashes and three right-to-left marks — and the
 * moment that string lands inside anything laid out left to right (a
 * `.technical` cell, a `dir="ltr"` span) the marks and the slashes reorder and
 * the customer reads "092027/03/". A named month has no separator to reorder:
 * "09 مارس 2027" reads the same way whatever surrounds it.
 *
 * The whole value is wrapped in first-strong isolates (U+2068 … U+2069) so a
 * date interpolated into a sentence — "renews on 09 مارس 2027" in either
 * language — is laid out as one unit and cannot borrow direction from the
 * words around it. The isolates are invisible, and `<time dateTime>` is the
 * place for a machine-readable copy where one is needed.
 *
 * Numerals stay Western in both languages, as everywhere else in the portal:
 * a date on an invoice must be unambiguous and copy-pasteable.
 */
const ISOLATE_START = '\u2068'
const ISOLATE_END = '\u2069'

function isolate(value: string): string {
  return `${ISOLATE_START}${value}${ISOLATE_END}`
}

export function formatDateTime(iso: string, locale: Locale): string {
  return isolate(
    new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(iso)),
  )
}

export function formatDate(iso: string, locale: Locale): string {
  return isolate(
    new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    }).format(new Date(iso)),
  )
}

export function formatRelative(iso: string, locale: Locale): string {
  const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  const deltaSeconds = (new Date(iso).getTime() - Date.now()) / 1000

  const thresholds: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
    ['week', 4.35],
    ['month', 12],
    ['year', Number.POSITIVE_INFINITY],
  ]

  let value = deltaSeconds
  for (const [unit, step] of thresholds) {
    if (Math.abs(value) < step) return formatter.format(Math.round(value), unit)
    value /= step
  }

  return formatter.format(Math.round(value), 'year')
}

const BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'] as const

/**
 * Formats a byte count using binary units, which is what hypervisors, disks
 * and hosting panels actually report. Presenting a GiB figure labelled "GB"
 * would understate a customer's disk by about seven percent.
 */
export function formatBytes(bytes: number, locale: Locale, fractionDigits = 1): string {
  if (!Number.isFinite(bytes) || bytes < 0) return '—'

  let value = bytes
  let unitIndex = 0
  while (value >= 1024 && unitIndex < BYTE_UNITS.length - 1) {
    value /= 1024
    unitIndex += 1
  }

  const formatted = new Intl.NumberFormat(`${locale}-u-nu-latn`, {
    maximumFractionDigits: unitIndex === 0 ? 0 : fractionDigits,
  }).format(value)

  return `${formatted} ${BYTE_UNITS[unitIndex]}`
}

export function formatNumber(value: number, locale: Locale): string {
  return new Intl.NumberFormat(`${locale}-u-nu-latn`).format(value)
}

export function formatPercent(fraction: number, locale: Locale): string {
  return new Intl.NumberFormat(`${locale}-u-nu-latn`, {
    style: 'percent',
    maximumFractionDigits: 1,
  }).format(fraction)
}
