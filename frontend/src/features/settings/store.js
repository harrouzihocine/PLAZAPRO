import { defineStore } from 'pinia'
import { useApi, getCsrf } from '@/composables/useApi'

// Authentication + current-user state (Sanctum SPA cookie mode). Holds no token.
export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    permissions: [],
    ready: false, // true once we've attempted to resolve the session at least once
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
    isAgent: (state) => Boolean(state.user?.is_agent),
    can: (state) => (permission) => state.permissions.includes(permission),
  },

  actions: {
    setUser(user) {
      this.user = user
      this.permissions = user?.permissions ?? []
    },

    clear() {
      this.user = null
      this.permissions = []
    },

    async login(email, password) {
      await getCsrf()
      const { data } = await useApi().post('/auth/login', { email, password })
      this.setUser(data.data)
      return this.user
    },

    async fetchMe() {
      try {
        const { data } = await useApi().get('/auth/me')
        this.setUser(data.data)
      } catch {
        this.clear()
      } finally {
        this.ready = true
      }
      return this.user
    },

    async logout() {
      try {
        await useApi().post('/auth/logout')
      } finally {
        this.clear()
      }
    },
  },
})
