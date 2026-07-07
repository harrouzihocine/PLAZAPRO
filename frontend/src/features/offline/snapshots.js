import { idb } from '@/features/offline/idb'
import { useAuthStore } from '@/features/settings/store'

// Read-cache snapshots: whitelisted stores persist their last successful
// response and serve it back when a fetch dies on a NETWORK error (never on a
// real 4xx/5xx — server answers stay authoritative). Keys are namespaced per
// user so a shared phone never leaks one agent's data to the next.
//
// Store-side pattern:
//   const data = await api.list()
//   this.items = data; this.offlineAt = null
//   cacheSnapshot('clients:list', data)
// and in the catch:
//   const snap = await readSnapshot('clients:list')
//   if (snap && isNetworkError(e)) { this.items = snap.data; this.offlineAt = snap.savedAt }

function userKey(name) {
  const id = useAuthStore().user?.id
  return id ? `${id}:${name}` : null
}

export function cacheSnapshot(name, data) {
  const key = userKey(name)
  if (key === null) return Promise.resolve()
  return idb.put('kv', { data, savedAt: new Date().toISOString() }, key).catch(() => {})
}

export async function readSnapshot(name) {
  const key = userKey(name)
  if (key === null) return null
  try {
    return (await idb.get('kv', key)) ?? null
  } catch {
    return null
  }
}

export function dropSnapshot(name) {
  const key = userKey(name)
  if (key === null) return Promise.resolve()
  return idb.del('kv', key).catch(() => {})
}

// Wipe one user's cached data (called on logout / real 401).
export function clearUserSnapshots(userId) {
  if (!userId) return Promise.resolve()
  return idb
    .delRange('kv', IDBKeyRange.bound(`${userId}:`, `${userId}:￿`))
    .catch(() => {})
}

// True when the request never got an answer (offline / DNS / timeout) —
// the only condition under which a snapshot may stand in.
export function isNetworkError(e) {
  return !e?.response && e?.code !== 'ERR_CANCELED'
}

// The catch-side one-liner: serves the snapshot through `apply(data, savedAt)`
// when the failure was a network error and a snapshot exists. Returns whether
// it did — callers rethrow otherwise so real server errors stay loud.
export async function serveSnapshot(e, name, apply) {
  if (!isNetworkError(e)) return false
  const snap = await readSnapshot(name)
  if (!snap) return false
  apply(snap.data, snap.savedAt)
  return true
}
