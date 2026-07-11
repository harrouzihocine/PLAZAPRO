// PLAZA PRO service worker — makes the SPA installable, instant to reopen,
// and fully bootable offline (the APK's no-signal mode depends on it).
//
// Deliberately conservative caching:
//   - API/auth/websocket traffic is NEVER touched (CRM data must never be stale).
//   - Navigations are network-first (a deploy reaches everyone on next open),
//     falling back to the cached shell only when offline.
//   - Hashed build assets (/assets/*) are cache-first: their names change on
//     every deploy, so a stale file can never be served under a fresh name.
//
// Whole-build precache (v4): every deploy renames every hashed chunk, so
// caching chunks only as pages happen to load them left offline boot broken
// after each deploy (first unvisited route = failed dynamic import). Instead,
// the build injects the full asset list below (vite.config.js plaza-sw-precache)
// and install() downloads ALL of it before this worker may activate:
//   - the swap is atomic — until the new build is fully cached, the previous
//     worker and its complete cache keep serving (install failure = no change);
//   - the cache name carries the build hash, so activate() drops exactly the
//     builds that are no longer current.
// Served raw (dev, direct from public/) the placeholder stays an empty array
// and the worker degrades to the old lazy behaviour.
const BUILD = '__PLAZA_BUILD__'
const PRECACHE = /*__PLAZA_PRECACHE__*/ []
const CACHE = 'plaza-pwa-v4-' + BUILD
const SHELL = ['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png']

// Small authenticated images (unit/location thumbnails, avatars) — the one
// slice of /api the worker MAY cache, so client files and galleries look
// complete offline. Unversioned: survives deploys; FIFO-trimmed at ~600
// entries (thumbs are Kb-sized — tens of MB at worst). Cache-first is safe:
// thumb URLs are per-media-id and avatar URLs carry a ?v= cache-buster.
const MEDIA_CACHE = 'plaza-media-v1'
const MEDIA_MAX_ENTRIES = 600
const MEDIA_RE = /(\/media\/\d+\/thumb|\/users\/\d+\/avatar)$/

// Paths the worker must stay out of: Laravel API + auth cookies + websockets.
const BYPASS = /^\/(api|sanctum|broadcasting|up|app)(\/|$)/

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) =>
      Promise.all([
        // Hashed assets are immutable — the HTTP cache may serve them.
        c.addAll(PRECACHE),
        // The shell files are NOT hashed — bypass the HTTP cache so the
        // index cached as '/' is really the build this manifest belongs to.
        c.addAll(SHELL.map((url) => new Request(url, { cache: 'reload' }))),
      ]),
    ),
  )
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((k) => k !== CACHE && k !== MEDIA_CACHE)
            .map((k) => caches.delete(k)),
        ),
      )
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)

  if (req.method !== 'GET' || url.origin !== self.location.origin) return

  // Thumbnails/avatars: checked BEFORE the API bypass — they live under /api
  // but are immutable images, not CRM data.
  if (MEDIA_RE.test(url.pathname)) {
    event.respondWith(mediaCacheFirst(req))
    return
  }

  // API-shaped paths are never touched beyond that one image slice.
  if (BYPASS.test(url.pathname)) return

  // App navigations: fresh from the network, the INSTALL-TIME shell offline.
  // Deliberately no runtime caching here: overwriting '/' with a newer
  // deploy's index while this cache still holds the OLD build's chunks left
  // phones with an offline shell whose scripts didn't exist — the "reopen
  // offline = dead page" bug. '/' enters the cache only in install(), in the
  // same atomic transaction as every asset it references.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/')))
    return
  }

  // Build assets and icons: cache-first (precached above), runtime backfill
  // for the stragglers the manifest skips (flag SVGs, legacy font formats).
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.match(req).then(
        (hit) =>
          hit ||
          fetch(req).then((res) => {
            if (res.ok) {
              const copy = res.clone()
              caches.open(CACHE).then((c) => c.put(req, copy))
            }
            return res
          }),
      ),
    )
  }
})

// ---------------------------------------------------------------------------
// Media cache: cache-first with FIFO trim. Only real same-origin 200 images
// may enter — a 401/redirect cached here would ghost past logins forever.

async function mediaCacheFirst(req) {
  const cache = await caches.open(MEDIA_CACHE)
  const hit = await cache.match(req)
  if (hit) return hit
  const res = await fetch(req)
  if (
    res.ok &&
    res.type === 'basic' &&
    (res.headers.get('content-type') || '').startsWith('image/')
  ) {
    await cache.put(req, res.clone())
    trimMediaCache(cache) // fire-and-forget
  }
  return res
}

async function trimMediaCache(cache) {
  try {
    const keys = await cache.keys() // insertion order — oldest first
    for (const key of keys.slice(0, Math.max(0, keys.length - MEDIA_MAX_ENTRIES))) {
      await cache.delete(key)
    }
  } catch {
    /* trimming is best-effort */
  }
}

// ---------------------------------------------------------------------------
// Background Sync: replay the offline outbox when connectivity returns, even
// with the app closed. A live page gets the hand-off instead (postMessage →
// outboxStore.sync()) so the Sync Center UI stays authoritative; the direct
// replay below only runs page-less, mirroring outboxStore.sync()'s grammar:
// 2xx → done · network error → stop, retry next window · 401 → stop (next
// login resumes) · other 4xx/5xx → terminal `failed` with the server's reason.

self.addEventListener('sync', (event) => {
  if (event.tag === 'plaza-outbox') event.waitUntil(replayOutbox())
})

async function replayOutbox() {
  const wins = await self.clients.matchAll({ type: 'window' })
  if (wins.length) {
    wins[0].postMessage({ type: 'plaza-outbox-sync' })
    return
  }

  // Sanctum needs the XSRF cookie echoed as a header; no cookieStore (older
  // WebViews) means no header to build — leave the queue for the next open.
  const xsrf = await readXsrfToken()
  if (!xsrf) return

  const session = await idbGet('kv', 'session:current')
  if (!session || !session.userId) return // signed out — never replay

  const all = await idbGetAll('outbox')
  const queue = all
    .filter((i) => i.userId === session.userId && i.status === 'pending')
    .sort((a, b) => a.createdAt.localeCompare(b.createdAt))

  let applied = 0
  for (const item of queue) {
    let res
    try {
      res = await fetch('/api/v1' + item.url, {
        method: (item.method || 'post').toUpperCase(),
        headers: {
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
          'X-Idempotency-Key': item.uuid,
          ...(item.files?.length ? {} : { 'Content-Type': 'application/json' }),
        },
        body: buildBody(item),
        credentials: 'same-origin',
      })
    } catch {
      break // connection dropped again — keep FIFO order, retry next window
    }
    if (res.ok) {
      await idbDelete('outbox', item.uuid)
      applied++
      continue
    }
    if (res.status === 401) break // session expired — the login watcher resumes
    // The server judged it — terminal, surfaced in the Sync Center on next open.
    item.attempts = (item.attempts || 0) + 1
    item.status = 'failed'
    item.lastError = {
      status: res.status,
      message: await res
        .json()
        .then((j) => j?.message || 'Rejected by the server.')
        .catch(() => 'Rejected by the server.'),
    }
    await idbPut('outbox', item)
  }

  if (applied) notifySynced(applied, session.locale)
}

// Mirror of apiOrQueue.buildPayload (keep in lockstep): JSON body, or
// multipart when the record carries files (Blobs persist fine in IDB).
function buildBody(item) {
  if (!item.files?.length) return item.body === null ? null : JSON.stringify(item.body)
  const form = new FormData()
  for (const [k, v] of Object.entries(item.body ?? {})) {
    if (v !== null && v !== undefined) form.append(k, String(v))
  }
  for (const f of item.files) form.append(f.field, f.blob, f.name || 'attachment')
  return form
}

async function readXsrfToken() {
  try {
    const cookie = await self.cookieStore?.get('XSRF-TOKEN')
    return cookie?.value ? decodeURIComponent(cookie.value) : null
  } catch {
    return null
  }
}

// Tray note in the user's own language (locale mirrored by authStore).
function notifySynced(n, locale) {
  const copy = {
    en: n === 1 ? '1 offline change synced.' : n + ' offline changes synced.',
    fr: n === 1 ? '1 modification hors ligne synchronisée.' : n + ' modifications hors ligne synchronisées.',
    ar: n === 1 ? 'تمت مزامنة تغيير واحد.' : 'تمت مزامنة ' + n + ' تغييرات.',
  }
  return self.registration
    .showNotification('PLAZA PRO', {
      body: copy[locale] || copy.en,
      tag: 'plaza-outbox-synced',
      icon: '/icons/icon-192.png',
    })
    .catch(() => {})
}

// Minimal IDB access — MUST mirror features/offline/idb.js (same db, version 1,
// stores kv + outbox). A future version bump there must be mirrored here, or
// the open below throws and background replay quietly stands down.

function idbOpen() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('plaza-offline', 1)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv')
      if (!db.objectStoreNames.contains('outbox')) {
        db.createObjectStore('outbox', { keyPath: 'uuid' })
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

function idbTx(store, mode, work) {
  return idbOpen().then(
    (db) =>
      new Promise((resolve, reject) => {
        const t = db.transaction(store, mode)
        const out = work(t.objectStore(store))
        t.oncomplete = () => {
          db.close()
          resolve(out?.result !== undefined ? out.result : undefined)
        }
        t.onerror = () => {
          db.close()
          reject(t.error)
        }
      }),
  )
}

const idbGet = (store, key) => idbTx(store, 'readonly', (s) => s.get(key)).catch(() => null)
const idbGetAll = (store) => idbTx(store, 'readonly', (s) => s.getAll()).catch(() => [])
const idbPut = (store, value) => idbTx(store, 'readwrite', (s) => s.put(value)).catch(() => {})
const idbDelete = (store, key) => idbTx(store, 'readwrite', (s) => s.delete(key)).catch(() => {})
