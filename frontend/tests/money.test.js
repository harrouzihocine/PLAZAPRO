import { describe, expect, it } from 'vitest'
import { dzdToMil, formatMoney, milToDzd } from '@/features/payments/money'

// Amounts are stored in base DZD; the UI quotes them in "Mil" (1 Mil = 10 000 DZD).
describe('formatMoney', () => {
  it('scales base DZD to Mil with the label and currency code', () => {
    expect(formatMoney('1000000.00')).toBe('100 Mil DZD')
    expect(formatMoney('12345678900.00', 'EUR')).toBe('1 234 567.89 Mil EUR')
  })

  it('trims trailing zeros but keeps significant decimals', () => {
    expect(formatMoney('1000.50')).toBe('0.1 Mil DZD')
    expect(formatMoney('50000')).toBe('5 Mil DZD')
    expect(formatMoney('55000')).toBe('5.5 Mil DZD')
  })

  it('handles negatives and blanks', () => {
    expect(formatMoney('-2000000.00')).toBe('-200 Mil DZD')
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('')).toBe('—')
  })
})

describe('mil/dzd scaling', () => {
  it('round-trips user input: Mil in, base-DZD string out, Mil back', () => {
    expect(milToDzd(100)).toBe('1000000.00')
    expect(dzdToMil('1000000.00')).toBe(100)
    expect(milToDzd('2.5')).toBe('25000.00')
  })

  it('propagates blanks as null', () => {
    expect(milToDzd('')).toBeNull()
    expect(dzdToMil(null)).toBeNull()
  })
})
