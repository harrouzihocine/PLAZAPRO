import { currentLocale } from '@/i18n'

// The showcase's anonymous analytics tracker. PII-free by design: the visitor
// is a random hex key in localStorage — no cookies, no IP, no identity.
// Events queue up and flush in small batches (the server caps a batch at 20)
// through sendBeacon so a tab close never loses the tail of a visit.

const SESSION_STORAGE_KEY = 'plaza.sid'
const FLUSH_AFTER_MS = 4000
const MAX_BATCH = 20

function sessionKey() {
  let sid = null
  try {
    sid = localStorage.getItem(SESSION_STORAGE_KEY)
  } catch {
    /* storage blocked — fall through to an in-memory key */
  }
  if (!/^[a-f0-9]{32}$/.test(sid ?? '')) {
    sid = [...crypto.getRandomValues(new Uint8Array(16))]
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('')
    try {
      localStorage.setItem(SESSION_STORAGE_KEY, sid)
    } catch {
      /* in-memory key lives for this page load only */
    }
  }
  return sid
}

const sid = sessionKey()
let queue = []
let timer = null
// The external page that brought the visitor here — sent once per page load.
let referrer = document.referrer && !document.referrer.startsWith(window.location.origin)
  ? document.referrer.slice(0, 300)
  : null

function send(body) {
  const json = JSON.stringify(body)
  const url = '/api/v1/public/track'
  if (navigator.sendBeacon) {
    navigator.sendBeacon(url, new Blob([json], { type: 'application/json' }))
    return
  }
  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: json,
    keepalive: true,
  }).catch(() => {})
}

function flush() {
  clearTimeout(timer)
  timer = null
  if (!queue.length) return
  const events = queue.splice(0, MAX_BATCH)
  const body = { session: sid, events, locale: currentLocale() }
  if (referrer) {
    body.referrer = referrer
    referrer = null
  }
  send(body)
  if (queue.length) flush()
}

// Whatever is still queued goes out when the tab hides (sendBeacon survives).
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') flush()
})

/**
 * Queue one event. `payload` may carry location_id / unit_id / path — anything
 * else is dropped server-side by validation.
 */
export function track(event, payload = {}) {
  queue.push({ event, ...payload })
  if (queue.length >= MAX_BATCH) flush()
  else timer ??= setTimeout(flush, FLUSH_AFTER_MS)
}

/** Route-level view events: every page, plus the project/unit deep counts. */
export function trackPage(route) {
  track('page_view', { path: route.fullPath.slice(0, 300) })
  if (route.name === 'showcase.project') {
    track('project_view', { location_id: Number(route.params.id) || null })
  } else if (route.name === 'showcase.unit') {
    track('unit_view', {
      location_id: Number(route.params.id) || null,
      unit_id: Number(route.params.unitId) || null,
    })
  }
}
