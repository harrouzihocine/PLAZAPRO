import { describe, expect, it } from 'vitest'
import { formatMoney } from '@/features/payments/money'

describe('formatMoney', () => {
  it('groups thousands and keeps two decimals with a currency code', () => {
    expect(formatMoney('1000.50')).toBe('1 000.50 DZD')
    expect(formatMoney('1234567.00', 'EUR')).toBe('1 234 567.00 EUR')
  })

  it('pads and trims to exactly two decimals', () => {
    expect(formatMoney('5')).toBe('5.00 DZD')
    expect(formatMoney('5.1')).toBe('5.10 DZD')
  })

  it('handles negatives and blanks', () => {
    expect(formatMoney('-2000.00')).toBe('-2 000.00 DZD')
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('')).toBe('—')
  })
})
