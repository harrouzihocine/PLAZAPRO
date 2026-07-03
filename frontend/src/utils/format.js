// Shared display formatting. Dates render in a compact, unambiguous
// "12 Mar 2026, 14:05" style; relative time is used for activity feeds.

const dateFmt = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})

const dateTimeFmt = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

export function formatDate(value) {
  if (!value) return '—'
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '—' : dateFmt.format(d)
}

export function formatDateTime(value) {
  if (!value) return '—'
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '—' : dateTimeFmt.format(d)
}

const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
const STEPS = [
  ['year', 31536000],
  ['month', 2592000],
  ['week', 604800],
  ['day', 86400],
  ['hour', 3600],
  ['minute', 60],
]

/** "3 hours ago" / "in 2 days" — falls back to the absolute date past ~4 weeks. */
export function timeAgo(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  const diff = (d.getTime() - Date.now()) / 1000
  const abs = Math.abs(diff)
  if (abs > 3024000) return formatDate(d) // > 5 weeks: show the date
  for (const [unit, secs] of STEPS) {
    if (abs >= secs) return rtf.format(Math.round(diff / secs), unit)
  }
  return 'just now'
}

/** Initials for avatars: "Sarah Benali" -> "SB". */
export function initials(name) {
  if (!name) return '?'
  return String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')
}

/** "office_visit" -> "Office visit". */
export function humanize(value) {
  if (value == null || value === '') return ''
  const s = String(value).replaceAll('_', ' ')
  return s.charAt(0).toUpperCase() + s.slice(1)
}
