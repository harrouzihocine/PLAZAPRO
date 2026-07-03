import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { unitsApi } from '@/features/inventory/api'

// State for the Units screens (the global filterable table and the per-location
// list on a project detail). Network lives in api.js; writes refetch the current
// view so server rules (unique reference, cancel guards, versioning) are reflected.
export const useUnitsStore = defineStore('units', {
  state: () => ({
    items: [],
    current: null, // the unit open on its detail page
    _fetchTicket: 0, // stale-response guard for auto-applied filters
    loading: false,
    saving: false,
    error: '',
    scope: null, // location_id when scoped to a project, else null (global)
    filters: {
      location_id: '',
      wilaya_id: [], // multi-select — geographic wilaya (from the unit's project)
      commune_id: [], // multi-select — commune, cascades from the selected wilaya(s)
      type_id: [], // multi-select
      floor_id: [], // multi-select
      sale_status: [], // multi-select
      priority: [], // multi-select — GTM (sales) priority
      min_area: '',
      max_area: '',
      min_price: '',
      max_price: '',
    },
  }),

  actions: {
    async fetch() {
      this.loading = true
      // Filters auto-apply (useAutoFilter): tag the request so a slower, older
      // response can never overwrite a newer one.
      const ticket = ++this._fetchTicket
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (Array.isArray(v)) {
            if (v.length) params[k] = v // axios serialises arrays as k[]=…
          } else if (v !== '' && v != null) {
            params[k] = v
          }
        }
        const items = await unitsApi.list(params)
        if (ticket !== this._fetchTicket) return
        this.scope = null
        this.items = items
      } finally {
        if (ticket === this._fetchTicket) this.loading = false
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

    async fetchOne(id) {
      this.loading = true
      try {
        this.current = await unitsApi.get(id)
        return this.current
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
        toastError(this.error)
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
