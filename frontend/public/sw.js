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
          // Only a healthy answer may become the offline shell: caching a 502
          // (mid-deploy) or a redirect here would serve THAT as the app later.
          if (res.ok) {
            const copy = res.clone()
            caches.open(CACHE).then((c) => c.put('/', copy))
          }
          return res
        })
        .catch(() => caches.match('/')),
    )
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
