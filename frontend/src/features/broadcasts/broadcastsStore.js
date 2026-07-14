import { defineStore } from 'pinia'
import { broadcastsApi } from '@/features/broadcasts/api'
import { toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// State for the Broadcasts page: the company-wide history (paginated) plus the
// send action. Network lives in api.js; this store only holds state + refreshes
// the list after a send.
export const useBroadcastsStore = defineStore('broadcasts', {
  state: () => ({
    items: [],
    meta: {},
    page: 1,
    loading: false,
    sending: false,
  }),

  actions: {
    async fetch(page = this.page) {
      this.loading = true
      try {
        const res = await broadcastsApi.list({ page })
        this.items = res.data
        this.meta = res.meta ?? {}
        this.page = res.meta?.current_page ?? page
      } finally {
        this.loading = false
      }
    },

    goToPage(page) {
      return this.fetch(page)
    },

    // Send a broadcast, then jump the history back to the first page so the new
    // row shows on top. Errors surface as a toast and re-throw so the composer
    // can keep the draft.
    async send(payload) {
      this.sending = true
      try {
        const broadcast = await broadcastsApi.send(payload)
        await this.fetch(1)
        return broadcast
      } catch (e) {
        toastError(e.response?.data?.message ?? t('common.actionFailed'))
        throw e
      } finally {
        this.sending = false
      }
    },
  },
})
