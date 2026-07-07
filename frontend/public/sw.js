// PLAZA PRO service worker — makes the SPA installable and instant to reopen.
//
// Deliberately conservative caching:
//   - API/auth/websocket traffic is NEVER touched (CRM data must never be stale).
//   - Navigations are network-first (a deploy reaches everyone on next open),
//     falling back to the cached shell only when offline.
//   - Hashed build assets (/assets/*) are cache-first: their names change on
//     every deploy, so a stale file can never be served under a fresh name.
//
// Bump the version to force-drop every old cache on the next visit.
// v2: the worker now also runs inside the Android shell (offline boot).
const CACHE = 'plaza-pwa-v2'
const SHELL = ['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png']

// Paths the worker must stay out of: Laravel API + auth cookies + websockets.
const BYPASS = /^\/(api|sanctum|broadcasting|up|app)(\/|$)/

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)

  // Only same-origin GETs are ever considered; API-shaped paths not even those.
  if (req.method !== 'GET' || url.origin !== self.location.origin || BYPASS.test(url.pathname)) {
    return
  }

  // App navigations: fresh from the network, cached shell when offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone()
          caches.open(CACHE).then((c) => c.put('/', copy))
          return res
        })
        .catch(() => caches.match('/')),
    )
    return
  }

  // Content-hashed build assets: cache-first, backfill on miss.
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
