import { defineStore } from 'pinia'
import { unitsApi } from '@/features/inventory/api'

// State for the Units screens (the global filterable table and the per-location
// list on a project detail). Network lives in api.js; writes refetch the current
// view so server rules (unique reference, cancel guards, versioning) are reflected.
export const useUnitsStore = defineStore('units', {
  state: () => ({
    items: [],
    loading: false,
    saving: false,
    error: '',
    scope: null, // location_id when scoped to a project, else null (global)
    filters: {
      location_id: '',
      type_id: '',
      floor_id: '',
      sale_status: '',
      min_price: '',
      max_price: '',
    },
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v != null) params[k] = v
        }
        this.scope = null
        this.items = await unitsApi.list(params)
      } finally {
        this.loading = false
      }
    },

    async fetchForLocation(locationId) {
      this.loading = true
      try {
        this.scope = locationId
        this.items = await unitsApi.list({ location_id: locationId })
      } finally {
        this.loading = false
      }
    },

    async refresh() {
      return this.scope ? this.fetchForLocation(this.scope) : this.fetch()
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        const result = await fn()
        await this.refresh()
        return result
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        throw e
      } finally {
        this.saving = false
      }
    },

    create(locationId, payload) {
      return this.mutate(() => unitsApi.create(locationId, payload))
    },

    update(id, payload) {
      return this.mutate(() => unitsApi.update(id, payload))
    },

    correct(id, payload) {
      return this.mutate(() => unitsApi.correct(id, payload))
    },

    cancel(id) {
      return this.mutate(() => unitsApi.cancel(id))
    },
  },
})
