import { defineStore } from 'pinia'

// Unsaved-modal drafts, persisted in localStorage so a misclick outside a
// modal (or a page change) never loses typed work. One entry per draft key;
// the composable (useModalDraft) is the only writer.
//
// Drafts contain client PII (names, notes), so storage is namespaced PER USER
// and the in-memory store is emptied on logout: on a shared browser, user B
// never sees (or silently restores) user A's drafts.
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
  }),

  getters: {
    count: (state) => Object.keys(state.items).length,
    list: (state) => Object.values(state.items).sort((a, b) => b.savedAt - a.savedAt),
  },

  actions: {
    /** Load the signed-in user's drafts (AppShell, once auth resolves). */
    hydrate(userId) {
      if (!userId || this.userId === userId) return
      this.userId = userId
      this.items = load(userId)
    },

    /** Forget everything in memory on logout (storage stays with its owner). */
    reset() {
      clearTimeout(persistTimer)
      this.userId = null
      this.items = {}
    },

    persist() {
      if (!this.userId) return
      clearTimeout(persistTimer)
      const key = storageKey(this.userId)
      const snapshot = () => localStorage.setItem(key, JSON.stringify(this.items))
      persistTimer = setTimeout(snapshot, 300)
    },

    save(key, { label, route, data }) {
      this.items[key] = { key, label, route, data, savedAt: Date.now() }
      this.persist()
    },

    discard(key) {
      delete this.items[key]
      this.persist()
    },

    get(key) {
      return this.items[key] ?? null
    },
  },
})
