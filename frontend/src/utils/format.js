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

// Local "today" as YYYY-MM-DD for native date inputs. `toISOString()` is UTC,
// which can roll a late-evening "today" to tomorrow (or vice-versa), so we build
// the string from local parts. Used as the `min` on scheduling pickers so a plan
// can never be set in the past.
export function todayInput(date = new Date()) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

// Server instant → local "YYYY-MM-DD" / "HH:mm" for prefilling date/time inputs
// (edit forms round-trip through these; slicing the raw ISO string would show
// the UTC parts, one hour off for Algeria). timeInput returns '' for a local
// midnight — the backend's "no time chosen" sentinel (see formatTimeIfSet).
export function dateInputValue(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '' : todayInput(d)
}

export function timeInputValue(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  if (d.getHours() === 0 && d.getMinutes() === 0) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`
}

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

const timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' })

// The backend stores an untimed next action as midnight (see BuildAgentAgenda's
// "All day"), so 00:00 means "no time chosen" rather than a real due time.
// Returns '' in that case so callers can skip showing a stray "00:00".
export function formatTimeIfSet(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return d.getHours() === 0 && d.getMinutes() === 0 ? '' : timeFmt.format(d)
}

// Always-on clock time (chat bubbles) — unlike formatTimeIfSet, midnight is a
// real send time here, not a "no time chosen" sentinel.
export function formatTime(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '' : timeFmt.format(d)
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
