import { toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// Blank-page recovery. Two production failure modes end in "the page shows
// nothing until I refresh":
//
//  1. Stale chunks after a deploy. Builds wipe frontend/dist, so the previous
//     build's hashed chunk files 404. Any session opened BEFORE the deploy — a
//     web tab, or the APK WebView Android keeps alive for days — fails to
//     lazy-load every page it hadn't visited yet, and the navigation dies
//     silently. Recovery: hard-load the target URL once; the fresh index.html
//     brings the new chunk names (nginx serves it always-revalidate).
//
//  2. A view's mount-time GET fails with a SERVER error (5xx mid-deploy, a
//     403…): stores rethrow, onMounted ignores the rejection, and the screen
//     renders empty with no feedback. Surface it with a toast. Network errors
//     (no response) are deliberately NOT toasted here — the OfflineBanner
//     already shows, and the reconnect watcher in AppShell reloads the view.

const RELOAD_AT_KEY = 'plaza-stale-chunk-reload-at'
const RELOAD_LOOP_WINDOW_MS = 30_000

// Chrome / Firefox / Safari wordings for a failed dynamic import, plus Vite's
// own message for a chunk's CSS dependency.
const STALE_CHUNK_RE =
  /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS/i

export function isStaleChunkError(error) {
  return STALE_CHUNK_RE.test(error?.message ?? '')
}

// Hard navigation to pick up a fresh index.html. The sessionStorage stamp
// stops a reload loop when the chunk is missing for another reason (truly
// offline and never cached): one attempt per 30s window, then give up quietly.
export function reloadForFreshBuild(path = null) {
  const last = Number(sessionStorage.getItem(RELOAD_AT_KEY) || 0)
  if (Date.now() - last < RELOAD_LOOP_WINDOW_MS) return
  sessionStorage.setItem(RELOAD_AT_KEY, String(Date.now()))
  const current = window.location.pathname + window.location.search + window.location.hash
  if (path && path !== current) {
    window.location.assign(path) // land on the page the user asked for
  } else {
    window.location.reload()
  }
}

let lastLoadToastAt = 0
const LOAD_TOAST_EVERY_MS = 5_000

export function installAppRecovery() {
  // Vite dispatches this when a lazy chunk (or its CSS) fails to load — the
  // canonical "deploy happened under an open session" signal.
  window.addEventListener('vite:preloadError', () => reloadForFreshBuild())

  // Mount-time GETs are fire-and-forget in every view (onMounted(store.fetch));
  // when one dies on a server answer the rejection lands here. One toast,
  // throttled — a burst of parallel fetches must not stack five of them.
  // 401 is excluded: the session-expiry flow (auth clear → login) handles it.
  window.addEventListener('unhandledrejection', (event) => {
    const e = event.reason
    if (!e?.isAxiosError || !e.response) return
    if ((e.config?.method ?? '').toLowerCase() !== 'get') return
    if (e.response.status === 401) return
    const now = Date.now()
    if (now - lastLoadToastAt < LOAD_TOAST_EVERY_MS) return
    lastLoadToastAt = now
    toastError(t('shell.loadFailedToast'))
  })
}
