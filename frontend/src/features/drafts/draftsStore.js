import { defineStore } from 'pinia'

// Unsaved-modal drafts, persisted in localStorage so a misclick outside a
// modal (or a page change) never loses typed work. One entry per draft key;
// the composable (useModalDraft) is the only writer.
const STORAGE_KEY = 'plaza:drafts'

function load() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY)) ?? {}
  } catch {
    return {}
  }
}

let persistTimer = null

export const useDraftsStore = defineStore('drafts', {
  state: () => ({
    items: load(), // key -> { key, label, route, data, savedAt }
  }),

  getters: {
    count: (state) => Object.keys(state.items).length,
    list: (state) => Object.values(state.items).sort((a, b) => b.savedAt - a.savedAt),
  },

  actions: {
    persist() {
      clearTimeout(persistTimer)
      persistTimer = setTimeout(
        () => localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items)),
        300,
      )
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
