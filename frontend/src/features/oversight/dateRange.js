// Default from/to for the oversight filter bars: the last 7 days, as
// YYYY-MM-DD strings (what the `type="date"` inputs expect).
function isoDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function defaultOversightRange() {
  const to = new Date()
  const from = new Date(to)
  from.setDate(from.getDate() - 7)
  return { from: isoDate(from), to: isoDate(to) }
}
