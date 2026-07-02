import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { departmentsApi } from '@/features/settings/api'

// State for the Departments admin screen. Network lives in api.js; every write
// refetches so the list reflects the server (including server-side rules like
// "can't cancel a department with active users").
export const useDepartmentsStore = defineStore('departments', {
  state: () => ({
    items: [],
    loading: false,
    saving: false,
    error: '',
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        this.items = await departmentsApi.list()
      } finally {
        this.loading = false
      }
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        await fn()
        await this.fetch()
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    create(payload) {
      return this.mutate(() => departmentsApi.create(payload))
    },

    update(id, payload) {
      return this.mutate(() => departmentsApi.update(id, payload))
    },

    cancel(id) {
      return this.mutate(() => departmentsApi.cancel(id))
    },
  },
})
