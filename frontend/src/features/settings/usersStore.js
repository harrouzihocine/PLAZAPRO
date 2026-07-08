import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { departmentsApi, rolesApi, usersApi } from '@/features/settings/api'

// State for the Users admin screen. Loads users plus the role and department
// catalogues (for the create/edit pickers). Filters are sent to the server;
// every write refetches so server rules (e.g. no self-lockout) are reflected.
export const useUsersStore = defineStore('users', {
  state: () => ({
    items: [],
    roles: [],
    departments: [],
    filters: { role_id: '', department_id: '', is_active: '' },
    loading: false,
    saving: false,
    error: '',
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null) params[k] = v
        }
        const [users, roles, departments] = await Promise.all([
          usersApi.list(params),
          rolesApi.list(),
          departmentsApi.list(),
        ])
        this.items = users
        this.roles = roles
        this.departments = departments
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
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    create(payload) {
      return this.mutate(() => usersApi.create(payload))
    },

    update(id, payload) {
      return this.mutate(() => usersApi.update(id, payload))
    },

    setActive(id, isActive) {
      return this.mutate(() => usersApi.setActive(id, isActive))
    },

    unlock(id) {
      return this.mutate(() => usersApi.unlock(id))
    },

    // Hand a (leaving) user's open work to a successor. Returns the moved
    // counts so the caller can toast a summary.
    transferWork(id, payload) {
      return this.mutate(() => usersApi.transferWork(id, payload))
    },

    cancel(id) {
      return this.mutate(() => usersApi.cancel(id))
    },
  },
})
