import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { MEDIA_MAX_BYTES, mediaApi } from '@/features/inventory/api'

// Reject files over the media ceiling before uploading, so the user gets an
// instant, clear message instead of a stalled upload that PHP kills with a 413.
const MEDIA_MAX_MB = Math.round(MEDIA_MAX_BYTES / (1024 * 1024))
function oversized(file) {
  if (file.size <= MEDIA_MAX_BYTES) return false
  const mb = (file.size / (1024 * 1024)).toFixed(1)
  toastError(`"${file.name}" is ${mb} MB — the maximum is ${MEDIA_MAX_MB} MB.`)
  return true
}

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
        toastError(this.error)
        throw e
      } finally {
        this.busy = false
      }
    },

    async uploadMany(files, collection) {
      const accepted = files.filter((file) => !oversized(file))
      if (!accepted.length) return
      await this.run(async () => {
        for (const file of accepted) {
          await mediaApi.upload(this.mediableType, this.mediableId, file, collection)
        }
        this.items = await mediaApi.list(this.mediableType, this.mediableId)
      })
    },

    replace(id, file) {
      if (oversized(file)) return
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
