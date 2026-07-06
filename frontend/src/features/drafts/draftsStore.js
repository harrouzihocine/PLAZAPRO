import { defineStore } from 'pinia'
import { draftsApi } from '@/features/drafts/api'

// Unsaved-modal drafts, persisted in localStorage so a misclick outside a
// modal (or a page change) never loses typed work. One entry per draft key;
// the composable (useModalDraft) is the only writer.
//
// Drafts contain client PII (names, notes), so storage is namespaced PER USER
// and the in-memory store is emptied on logout: on a shared browser, user B
// never sees (or silently restores) user A's drafts.
//
// Draft METADATA (key/label/route — never the typed contents) is also mirrored
// to the server so the drafts-oversight page can see and clear stuck work. On
// hydrate we reconcile: a local draft whose key an admin removed server-side is
// dropped locally too.
const storageKey = (userId) => `plaza:drafts:${userId}`

function load(userId) {
  try {
    return JSON.parse(localStorage.getItem(storageKey(userId))) ?? {}
  } catch {
    return {}
  }
}

let persistTimer = null

export const useDraftsStore = defineStore('drafts', {
  state: () => ({
    userId: null, // set by hydrate() once the session user is known
    items: {}, // key -> { key, label, route, data, savedAt }
    _dirty: new Set(), // keys whose metadata needs a server upsert
  }),

  getters: {
    count: (state) => Object.keys(state.items).length,
    list: (state) => Object.values(state.items).sort((a, b) => b.savedAt - a.savedAt),
  },

  actions: {
    /** Load the signed-in user's drafts + reconcile against the server. */
    async hydrate(userId) {
      if (!userId || this.userId === userId) return
      this.userId = userId
      this.items = load(userId)
      await this.reconcile()
    },

    /** Drop local drafts an admin cleared server-side (best-effort). */
    async reconcile() {
      try {
        const server = await draftsApi.list()
        const serverKeys = new Set(server.map((d) => d.draft_key))
        for (const key of Object.keys(this.items)) {
          if (!serverKeys.has(key)) {
            delete this.items[key]
          }
        }
        this.persist()
      } catch {
        /* offline / not reachable — keep local as-is */
      }
    },

    /** Forget everything in memory on logout (storage stays with its owner). */
    reset() {
      clearTimeout(persistTimer)
      this.userId = null
      this.items = {}
      this._dirty = new Set()
    },

    persist() {
      if (!this.userId) return
      clearTimeout(persistTimer)
      const key = storageKey(this.userId)
      persistTimer = setTimeout(() => {
        localStorage.setItem(key, JSON.stringify(this.items))
        this.syncDirty()
      }, 300)
    },

    /** Push queued metadata upserts to the server (best-effort, fire-and-forget). */
    syncDirty() {
      for (const key of this._dirty) {
        const item = this.items[key]
        if (item) draftsApi.upsert({ key, label: item.label, route: item.route }).catch(() => {})
      }
      this._dirty = new Set()
    },

    save(key, { label, route, data }) {
      this.items[key] = { key, label, route, data, savedAt: Date.now() }
      this._dirty.add(key)
      this.persist()
    },

    discard(key) {
      delete this.items[key]
      this._dirty.delete(key)
      this.persist()
      draftsApi.remove(key).catch(() => {})
    },

    get(key) {
      return this.items[key] ?? null
    },
  },
})
