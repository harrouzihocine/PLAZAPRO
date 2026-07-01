import { defineStore } from 'pinia'
import { locationsApi } from '@/features/inventory/api'

// State for the Locations (projects) screen. Network lives in api.js; every write
// refetches so the list reflects the server (status filters, cancel guards, ...).
export const useLocationsStore = defineStore('locations', {
  state: () => ({
    items: [],
    current: null,
    loading: false,
    saving: false,
    error: '',
    filters: { q: '', area_id: '' },
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const params = {}
        if (this.filters.q) params.q = this.filters.q
        if (this.filters.area_id) params.area_id = this.filters.area_id
        this.items = await locationsApi.list(params)
      } finally {
        this.loading = false
      }
    },

    async fetchOne(id) {
      this.loading = true
      try {
        this.current = await locationsApi.get(id)
        return this.current
      } finally {
        this.loading = false
      }
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        const result = await fn()
        await this.fetch()
        return result
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        throw e
      } finally {
        this.saving = false
      }
    },

    create(payload) {
      return this.mutate(() => locationsApi.create(payload))
    },

    update(id, payload) {
      return this.mutate(() => locationsApi.update(id, payload))
    },

    cancel(id) {
      return this.mutate(() => locationsApi.cancel(id))
    },
  },
})
