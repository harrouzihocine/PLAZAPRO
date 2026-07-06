import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { auditApi } from '@/features/analytics/api'
import { todayInput } from '@/utils/format'

// State for the admin audit feed. Filters are sent to the server (user, action,
// subject type, date range); the feed is paginated and strictly read-only. Empty
// filters are dropped so the query stays clean. Network calls live in
// analytics/api.js.
export const useAuditStore = defineStore('audit', {
  state: () => ({
    items: [],
    meta: {},
    _fetchTicket: 0, // stale-response guard for auto-applied filters
    filters: { user_id: '', action: '', subject_type: '', from: '', to: '' },
    page: 1,
    loading: false,
    exporting: false,
    error: '',
  }),

  getters: {
    // The active filters, minus blanks — reused by both fetch and export.
    activeParams: (state) => {
      const params = {}
      for (const [k, v] of Object.entries(state.filters)) {
        if (v !== '' && v !== null) params[k] = v
      }
      return params
    },
  },

  actions: {
    async fetch() {
      this.loading = true
      this.error = ''
      // Filters auto-apply (useAutoFilter): tag the request so a slower, older
      // response can never overwrite a newer one.
      const ticket = ++this._fetchTicket
      try {
        const { items, meta } = await auditApi.list({ ...this.activeParams, page: this.page })
        if (ticket !== this._fetchTicket) return
        this.items = items
        this.meta = meta
      } catch (e) {
        if (ticket !== this._fetchTicket) return
        this.error = e.response?.data?.message ?? 'Could not load the audit feed.'
        toastError(this.error)
      } finally {
        if (ticket === this._fetchTicket) this.loading = false
      }
    },

    // Reset to page 1 whenever the filters change, then reload.
    async applyFilters() {
      this.page = 1
      await this.fetch()
    },

    async goToPage(page) {
      this.page = page
      await this.fetch()
    },

    // Download the filtered feed as a CSV file. The server records the export in
    // the audit trail; here we just turn the returned Blob into a browser download.
    async exportCsv() {
      this.exporting = true
      this.error = ''
      try {
        const blob = await auditApi.export(this.activeParams)
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `audit-${todayInput()}.csv`
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Could not export the audit feed.'
        toastError(this.error)
      } finally {
        this.exporting = false
      }
    },
  },
})
