import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { permissionsApi, rolesApi } from '@/features/settings/api'
import { t } from '@/i18n'

// State for the Roles & permissions admin. Loads the role list plus the full
// permission catalogue (for the matrix); every write refetches so server rules
// (e.g. super-admin/active-user cancel guards) are reflected.
export const useRolesStore = defineStore('roles', {
  state: () => ({
    roles: [],
    permissions: [],
    loading: false,
    saving: false,
    error: '',
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const [roles, permissions] = await Promise.all([rolesApi.list(), permissionsApi.list()])
        this.roles = roles
        this.permissions = permissions
      } finally {
        this.loading = false
      }
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        const result = await fn()
        this.roles = await rolesApi.list()
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
      return this.mutate(() => rolesApi.create(payload))
    },

    update(id, payload) {
      return this.mutate(() => rolesApi.update(id, payload))
    },

    cancel(id) {
      return this.mutate(() => rolesApi.cancel(id))
    },
  },
})
