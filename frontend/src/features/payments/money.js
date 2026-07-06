// Presentation-only money formatting in the Algerian "Mil" convention.
// Amounts are STORED as exact DZD (server bcmath, decimal(12,2)); the UI quotes
// them in "Mil" where 1 Mil = 10 000 DZD (so 100 Mil = 1 000 000 DZD). The
// frontend never computes money — it only SCALES the decimal strings the API
// returns for display, and scales user-entered Mil back to base DZD before it
// is sent. Change MIL / MIL_LABEL here to re-tune the whole app.

/** 1 typed unit ("Mil") = 10 000 DZD. */
export const MIL = 10000
export const MIL_LABEL = 'Mil'

/**
 * Format a base-DZD value (decimal string e.g. "1000000.00", or a number) for
 * display in Mil — e.g. "100 Mil DZD". Trailing zeros on the Mil value are
 * trimmed, the integer part is thousands-separated, and null/blank → a dash.
 */
export function formatMoney(value, currency = 'DZD') {
  if (value === null || value === undefined || value === '') return '—'
  const mil = Number(value) / MIL
  if (Number.isNaN(mil)) return '—'
  return `${formatMilNumber(mil)} ${MIL_LABEL} ${currency}`
}

/** The bare number a base-DZD value represents in Mil (no label), or null. */
export function dzdToMil(value) {
  if (value === null || value === undefined || value === '') return null
  const mil = Number(value) / MIL
  return Number.isNaN(mil) ? null : mil
}

/** Scale a user-entered Mil amount back to a base-DZD decimal string (2dp). */
export function milToDzd(input) {
  if (input === null || input === undefined || input === '') return null
  const dzd = Number(input) * MIL
  return Number.isNaN(dzd) ? null : dzd.toFixed(2)
}

/** Group the integer part with thin spaces and keep up to 2 trimmed decimals. */
function formatMilNumber(mil) {
  const sign = mil < 0 ? '-' : ''
  const [intPart, decPart = ''] = Math.abs(mil).toFixed(2).split('.')
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  const dec = decPart.replace(/0+$/, '')
  return `${sign}${grouped}${dec ? '.' + dec : ''}`
}
