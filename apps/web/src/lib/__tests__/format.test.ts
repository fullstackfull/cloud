import { describe, expect, it } from 'vitest'

import { formatBytes, formatMoney, formatNumber, formatPercent } from '../format'

describe('formatMoney', () => {
  it('renders a three-decimal currency at full precision', () => {
    // KWD has three minor digits. Rendering it with two would misstate every
    // Kuwaiti invoice by up to nine fils.
    const formatted = formatMoney({ minor_units: 12500, currency: 'KWD', amount: '12.500' }, 'en')

    expect(formatted).toContain('12.500')
    expect(formatted).toContain('KWD')
  })

  it('renders a two-decimal currency at full precision', () => {
    expect(formatMoney({ minor_units: 1999, currency: 'USD', amount: '19.99' }, 'en')).toContain('19.99')
  })

  it('uses Western numerals in Arabic so amounts stay unambiguous', () => {
    const formatted = formatMoney({ minor_units: 12500, currency: 'KWD', amount: '12.500' }, 'ar')

    expect(formatted).toMatch(/12/)
    // Eastern Arabic numerals would be correct typography but would break
    // copy-paste into a bank transfer or a support ticket.
    expect(formatted).not.toMatch(/[٠-٩]/)
  })
})

describe('formatBytes', () => {
  it('uses binary units, matching what hypervisors and disks report', () => {
    // Labelling GiB as GB would understate a customer's disk by ~7%.
    expect(formatBytes(1024, 'en')).toBe('1 KiB')
    expect(formatBytes(1024 ** 3, 'en')).toBe('1 GiB')
    expect(formatBytes(1.5 * 1024 ** 3, 'en')).toBe('1.5 GiB')
  })

  it('shows whole bytes without a fraction', () => {
    expect(formatBytes(512, 'en')).toBe('512 B')
  })

  it('returns a placeholder for a value that cannot be rendered', () => {
    expect(formatBytes(Number.NaN, 'en')).toBe('—')
    expect(formatBytes(-1, 'en')).toBe('—')
  })
})

describe('locale-aware numbers', () => {
  it('keeps numerals Western in Arabic', () => {
    expect(formatNumber(1234, 'ar')).not.toMatch(/[٠-٩]/)
    expect(formatPercent(0.856, 'ar')).not.toMatch(/[٠-٩]/)
  })

  it('formats a percentage to one decimal', () => {
    expect(formatPercent(0.856, 'en')).toBe('85.6%')
  })
})
