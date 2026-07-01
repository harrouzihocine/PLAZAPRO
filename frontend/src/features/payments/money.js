// Presentation-only money formatting. All arithmetic and balances come from the
// server (bcmath, decimal(12,2)); the frontend never computes money — it only
// formats the decimal strings the API returns.

/**
 * Format a decimal string (e.g. "1000.50") for display with a thousands
 * separator and a trailing currency code. Falls back to a dash for null/blank.
 */
export function formatMoney(value, currency = 'DZD') {
  if (value === null || value === undefined || value === '') return '—'
  const [intPart, decPart = '00'] = String(value).split('.')
  const sign = intPart.startsWith('-') ? '-' : ''
  const digits = intPart.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  return `${sign}${digits}.${decPart.padEnd(2, '0').slice(0, 2)} ${currency}`.trim()
}
