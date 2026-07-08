// Shared display formatting. Dates render in a compact, unambiguous
// "12 Mar 2026, 14:05" style; relative time is used for activity feeds.
// Everything follows the UI language: 'ar-DZ' notably gives the Algerian
// month names (جانفي، فيفري…) with Western digits, matching how the company
// actually writes dates in Arabic.

import { currentLocale, t } from '@/i18n'

const INTL_LOCALES = { en: 'en-GB', fr: 'fr-FR', ar: 'ar-DZ' }

export function intlLocale() {
  return INTL_LOCALES[currentLocale()] ?? 'en-GB'
}

// Intl formatters are expensive to build — cache one per (kind, locale).
const fmtCache = new Map()

function fmt(kind, options) {
  const key = `${kind}:${currentLocale()}`
  if (!fmtCache.has(key)) fmtCache.set(key, new Intl.DateTimeFormat(intlLocale(), options))
  return fmtCache.get(key)
}

const DATE_OPTS = { day: 'numeric', month: 'short', year: 'numeric' }
const DATE_TIME_OPTS = { ...DATE_OPTS, hour: '2-digit', minute: '2-digit' }
const TIME_OPTS = { hour: '2-digit', minute: '2-digit' }

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
  return Number.isNaN(d.getTime()) ? '—' : fmt('date', DATE_OPTS).format(d)
}

export function formatDateTime(value) {
  if (!value) return '—'
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '—' : fmt('datetime', DATE_TIME_OPTS).format(d)
}

// The backend stores an untimed next action as midnight (see BuildAgentAgenda's
// "All day"), so 00:00 means "no time chosen" rather than a real due time.
// Returns '' in that case so callers can skip showing a stray "00:00".
export function formatTimeIfSet(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return d.getHours() === 0 && d.getMinutes() === 0 ? '' : fmt('time', TIME_OPTS).format(d)
}

// Always-on clock time (chat bubbles) — unlike formatTimeIfSet, midnight is a
// real send time here, not a "no time chosen" sentinel.
export function formatTime(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? '' : fmt('time', TIME_OPTS).format(d)
}

function rtf() {
  const key = `rtf:${currentLocale()}`
  if (!fmtCache.has(key)) {
    fmtCache.set(key, new Intl.RelativeTimeFormat(intlLocale(), { numeric: 'auto' }))
  }
  return fmtCache.get(key)
}

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
    if (abs >= secs) return rtf().format(Math.round(diff / secs), unit)
  }
  return t('common.justNow')
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

// Room/floor values come from dynamic lists and are often already labels
// ("F3", "6th Floor", "RDC") — only BARE NUMBERS get a label added, so a card
// never shows "1 · 1" but also never doubles up ("Floor 6th Floor").
export function roomsLabel(value) {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? t('units.rooms', n) : String(value)
}

export function floorLabel(value) {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  if (!Number.isFinite(n)) return String(value)
  return n === 0 ? t('units.groundFloor') : t('units.floorN', { n: value })
}

// One human-readable unit summary — "REF-A12 · Apartment · 3 rooms · Floor 2 ·
// 85 m²". These facts used to be joined unlabeled ("… · 1 · 1 · …"), which read
// as meaningless digits on the phone cards. Accepts both API shapes
// (type/property_type). Pass a formatted price string via `price` to append it.
export function unitLine(u, { price = null } = {}) {
  if (!u) return ''
  return [
    u.reference,
    humanize(u.property_type ?? u.type),
    roomsLabel(u.room_number),
    floorLabel(u.floor),
    u.area_sqm ? `${u.area_sqm} m²` : null,
    price,
  ]
    .filter(Boolean)
    .join(' · ')
}

// How many filters are actually applied — feeds the badge on the phone
// "Filters" toggle. Empty string/null/undefined/empty array = not applied.
export function countActiveFilters(filters, ignore = []) {
  return Object.entries(filters ?? {}).filter(
    ([key, v]) =>
      !ignore.includes(key) &&
      v !== null &&
      v !== undefined &&
      v !== '' &&
      v !== false &&
      !(Array.isArray(v) && v.length === 0),
  ).length
}
