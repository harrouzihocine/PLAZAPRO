import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { locationsApi } from '@/features/inventory/api'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'
import { t } from '@/i18n'

// State for the Locations (projects) screen. Network lives in api.js; every write
// refetches so the list reflects the server (status filters, cancel guards, ...).
export const useLocationsStore = defineStore('locations', {
  state: () => ({
    items: [],
    archivedItems: [],
    current: null,
    _fetchTicket: 0, // stale-response guard for auto-applied filters
    loading: false,
    saving: false,
    error: '',
    filters: { q: '', wilaya_id: '', commune_id: '', priority: '' },
  }),

  actions: {
    async fetch() {
      this.loading = true
      // Filters auto-apply (useAutoFilter): tag the request so a slower, older
      // response can never overwrite a newer one.
      const ticket = ++this._fetchTicket
      const params = {}
      if (this.filters.q) params.q = this.filters.q
      if (this.filters.wilaya_id) params.wilaya_id = this.filters.wilaya_id
      if (this.filters.commune_id) params.commune_id = this.filters.commune_id
      if (this.filters.priority) params.priority = this.filters.priority
      // Offline snapshot covers the unfiltered list only.
      const defaultView = Object.keys(params).length === 0
      try {
        const items = await locationsApi.list(params)
        if (ticket !== this._fetchTicket) return
        this.items = items
        if (defaultView) cacheSnapshot('locations:list', items)
      } catch (e) {
        if (ticket !== this._fetchTicket) return
        const served =
          defaultView &&
          (await serveSnapshot(e, 'locations:list', (data) => {
            this.items = data
          }))
        if (!served) throw e
      } finally {
        if (ticket === this._fetchTicket) this.loading = false
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
        this.error = e.response?.data?.message ?? t('common.actionFailed')
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    create(payload) {
      return this.mutate(() => locationsApi.create(payload))
    },

    async update(id, payload) {
      const result = await this.mutate(() => locationsApi.update(id, payload))
      // Keep the open detail record (its cover hero, etc.) in sync.
      if (this.current?.id === id) this.current = result
      return result
    },

    async loadArchived() {
      this.archivedItems = await locationsApi.list({ status: 'archived' })
      return this.archivedItems
    },

    // Archive = reversible: hides the project + its units/boxes until reactivated.
    async archive(id) {
      await this.mutate(() => locationsApi.archive(id))
      return this.loadArchived()
    },

    async reactivate(id) {
      await this.mutate(() => locationsApi.reactivate(id))
      return this.loadArchived()
    },

    // Remove = terminal: cancels the project and everything inside it (kept + audited).
    cancel(id) {
      return this.mutate(() => locationsApi.cancel(id))
    },
  },
})
