import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { boxesApi } from '@/features/inventory/api'
import { t } from '@/i18n'

// State for the boxes list shown under a project (parking / storage). Network
// lives in api.js; writes refetch the current location's boxes.
export const useBoxesStore = defineStore('boxes', {
  state: () => ({
    items: [],
    loading: false,
    saving: false,
    error: '',
    locationId: null,
  }),

  actions: {
    async fetchForLocation(locationId) {
      this.loading = true
      this.locationId = locationId
      try {
        this.items = await boxesApi.list({ location_id: locationId })
      } finally {
        this.loading = false
      }
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        const result = await fn()
        await this.fetchForLocation(this.locationId)
        return result
      } catch (e) {
        this.error = e.response?.data?.message ?? t('common.actionFailed')
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    create(locationId, payload) {
      return this.mutate(() => boxesApi.create(locationId, payload))
    },

    update(id, payload) {
      return this.mutate(() => boxesApi.update(id, payload))
    },

    cancel(id) {
      return this.mutate(() => boxesApi.cancel(id))
    },
  },
})
