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

export function formatDateTime(iso: string, locale: Locale): string {
  return new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(iso))
}

export function formatDate(iso: string, locale: Locale): string {
  return new Intl.DateTimeFormat(`${locale}-u-nu-latn`, { dateStyle: 'medium' }).format(new Date(iso))
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
