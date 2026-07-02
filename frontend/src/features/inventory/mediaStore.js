import { defineStore } from 'pinia'
import { mediaApi } from '@/features/inventory/api'

// State for a mediable's gallery (upload / reorder / replace / remove). Keyed to
// one mediable at a time (the project or unit currently open).
export const useMediaStore = defineStore('media', {
  state: () => ({
    items: [],
    loading: false,
    busy: false,
    error: '',
    mediableType: null,
    mediableId: null,
  }),

  getters: {
    // Group the flat list into tabs. `items` arrives ordered by collection then
    // sort_order (the index query), so each group preserves the gallery order.
    byCollection: (state) => {
      const groups = {}
      for (const item of state.items) {
        ;(groups[item.collection] ??= []).push(item)
      }
      return groups
    },
  },

  actions: {
    async load(mediableType, mediableId) {
      this.mediableType = mediableType
      this.mediableId = mediableId
      this.loading = true
      try {
        this.items = await mediaApi.list(mediableType, mediableId)
      } finally {
        this.loading = false
      }
    },

    async run(fn) {
      this.busy = true
      this.error = ''
      try {
        return await fn()
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        throw e
      } finally {
        this.busy = false
      }
    },

    async uploadMany(files, collection) {
      await this.run(async () => {
        for (const file of files) {
          await mediaApi.upload(this.mediableType, this.mediableId, file, collection)
        }
        this.items = await mediaApi.list(this.mediableType, this.mediableId)
      })
    },

    replace(id, file) {
      return this.run(async () => {
        await mediaApi.replace(id, file)
        this.items = await mediaApi.list(this.mediableType, this.mediableId)
      })
    },

    remove(id) {
      return this.run(async () => {
        await mediaApi.cancel(id)
        this.items = await mediaApi.list(this.mediableType, this.mediableId)
      })
    },

    // Move an item up/down within its own tab. `items` is contiguous per
    // collection, so the neighbour at idx+delta is same-tab unless we're at a
    // group boundary (guarded). Only that collection's ids are reordered, so
    // per-collection sort_order stays independent.
    async move(id, delta) {
      const idx = this.items.findIndex((m) => m.id === id)
      const target = idx + delta
      if (idx < 0 || target < 0 || target >= this.items.length) return
      const collection = this.items[idx].collection
      if (this.items[target].collection !== collection) return
      const ordered = [...this.items]
      ;[ordered[idx], ordered[target]] = [ordered[target], ordered[idx]]
      this.items = ordered
      const collectionIds = ordered.filter((m) => m.collection === collection).map((m) => m.id)
      await this.run(() => mediaApi.reorder(this.mediableType, this.mediableId, collectionIds))
    },
  },
})
