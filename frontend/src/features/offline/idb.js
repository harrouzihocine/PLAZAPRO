// Minimal promise wrapper over IndexedDB — no dependency needed for two object
// stores. One database for the whole offline layer:
//   kv     — read-cache snapshots, keyed `${userId}:${name}` (snapshots.js)
//   outbox — queued offline writes, keyed by uuid (outboxStore)
const DB_NAME = 'plaza-offline'
const DB_VERSION = 1

let _db = null

function open() {
  if (_db) return Promise.resolve(_db)
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv')
      if (!db.objectStoreNames.contains('outbox')) {
        db.createObjectStore('outbox', { keyPath: 'uuid' })
      }
    }
    req.onsuccess = () => {
      _db = req.result
      // A version change elsewhere (another tab upgrading) must not deadlock it.
      _db.onversionchange = () => {
        _db.close()
        _db = null
      }
      resolve(_db)
    }
    req.onerror = () => reject(req.error)
  })
}

function tx(store, mode, work) {
  return open().then(
    (db) =>
      new Promise((resolve, reject) => {
        const t = db.transaction(store, mode)
        const s = t.objectStore(store)
        const out = work(s)
        t.oncomplete = () => resolve(out?.result !== undefined ? out.result : undefined)
        t.onerror = () => reject(t.error)
        t.onabort = () => reject(t.error)
      }),
  )
}

export const idb = {
  get: (store, key) => tx(store, 'readonly', (s) => s.get(key)),
  put: (store, value, key) => tx(store, 'readwrite', (s) => s.put(value, key)),
  del: (store, key) => tx(store, 'readwrite', (s) => s.delete(key)),
  getAll: (store, range = null) => tx(store, 'readonly', (s) => s.getAll(range)),
  delRange: (store, range) => tx(store, 'readwrite', (s) => s.delete(range)),
  clear: (store) => tx(store, 'readwrite', (s) => s.clear()),
}
