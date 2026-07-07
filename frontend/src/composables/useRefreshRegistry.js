import { getCurrentInstance, onMounted, onUnmounted } from 'vue'

// Route-scoped refresh registry for pull-to-refresh (and any future "reload
// this screen" affordance). A view calls useRefreshable(fn) with its own data
// reload; the PTR gesture runs every handler registered under the current
// route name. Views that never registered fall back to a full reload — PTR
// must always visibly refresh, and the service worker makes reloads cheap.
const handlers = new Map() // routeName -> Set<fn>

export function useRefreshable(fn) {
  // Route name is resolved at mount time from the owning component's route.
  const instance = getCurrentInstance()
  let key = null
  onMounted(() => {
    key = instance?.proxy?.$route?.name ?? null
    if (!key) return
    if (!handlers.has(key)) handlers.set(key, new Set())
    handlers.get(key).add(fn)
  })
  onUnmounted(() => {
    if (!key) return
    handlers.get(key)?.delete(fn)
    if (handlers.get(key)?.size === 0) handlers.delete(key)
  })
}

export async function runRefresh(routeName) {
  const set = handlers.get(routeName)
  if (!set || set.size === 0) {
    window.location.reload()
    // Keep the spinner up while the page tears down.
    return new Promise(() => {})
  }
  await Promise.allSettled([...set].map((fn) => fn()))
}

export function hasRefreshHandler(routeName) {
  return (handlers.get(routeName)?.size ?? 0) > 0
}

// ── PTR lock: long-lived gestures (dispatch board drags) suspend PTR ──
let ptrLocks = 0

export function acquirePtrLock() {
  ptrLocks++
}

export function releasePtrLock() {
  ptrLocks = Math.max(0, ptrLocks - 1)
}

export function ptrLocked() {
  return ptrLocks > 0
}
