import { getCurrentInstance, onMounted, onUnmounted } from 'vue'

// Route-scoped refresh registry for pull-to-refresh (and any future "reload
// this screen" affordance). A view calls useRefreshable(fn) with its own data
// reload; the PTR gesture runs every handler registered under the current
// route name. Views that never registered fall back to a full reload — PTR
// must always visibly refresh, and the service worker makes reloads cheap.
const handlers = new Map() // routeName -> Set<fn>

// `routeNames`: pass explicitly when one component instance backs SEVERAL
// route records (ChatView serves both `chat` and `chat.thread` — Vue reuses
// the instance across them, so mount-time capture alone would only register
// whichever route mounted first).
export function useRefreshable(fn, routeNames = null) {
  const instance = getCurrentInstance()
  let keys = []
  onMounted(() => {
    keys = routeNames ?? [instance?.proxy?.$route?.name].filter(Boolean)
    for (const key of keys) {
      if (!handlers.has(key)) handlers.set(key, new Set())
      handlers.get(key).add(fn)
    }
  })
  onUnmounted(() => {
    for (const key of keys) {
      handlers.get(key)?.delete(fn)
      if (handlers.get(key)?.size === 0) handlers.delete(key)
    }
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
